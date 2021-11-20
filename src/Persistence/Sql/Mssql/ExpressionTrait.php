<?php

declare(strict_types=1);

namespace Atk4\Data\Persistence\Sql\Mssql;

use Doctrine\DBAL\Result as DbalResult;

trait ExpressionTrait
{
    protected function escapeIdentifier(string $value): string
    {
        return $this->fixOpenEscapeChar(parent::escapeIdentifier($value));
    }

    protected function escapeIdentifierSoft(string $value): string
    {
        return $this->fixOpenEscapeChar(parent::escapeIdentifierSoft($value));
    }

    private function fixOpenEscapeChar(string $v): string
    {
        return preg_replace('~(?:\'(?:\'\'|\\\\\'|[^\'])*\')?+\K\]([^\[\]\'"(){}]*?)\]~s', '[$1]', $v);
    }

    private function _render(): string
    {
        // convert all SQL strings to NVARCHAR, eg 'text' to N'text'
        return preg_replace_callback('~(^|.)(\'(?:\'\'|\\\\\'|[^\'])*\')~s', function ($matches) {
            return $matches[1] . (!in_array($matches[1], ['N', '\'', '\\'], true) ? 'N' : '') . $matches[2];
        }, parent::render());
    }

    // {{{ MSSQL does not support named parameters, so convert them to numerical inside execute

    /** @var array|null */
    private $queryParamsBackup;
    /** @var string|null */
    private $queryRender;

    /**
     * @return DbalResult|\PDOStatement PDOStatement iff for DBAL 2.x
     */
    public function execute(object $connection = null): object
    {
        if ($this->queryParamsBackup !== null) {
            return parent::execute($connection);
        }

        $this->queryParamsBackup = $this->params;
        try {
            $newParams = [];
            $i = 0;
            $j = 0;
            $this->queryRender = preg_replace_callback(
                '~(?:\'(?:\'\'|\\\\\'|[^\'])*\')?+\K(?:\?|:\w+)~s',
                function ($matches) use (&$newParams, &$i, &$j) {
                    $newParams[++$i] = $this->params[$matches[0] === '?' ? ++$j : $matches[0]];

                    return '?';
                },
                $this->_render()
            );
            $this->params = $newParams;

            return parent::execute($connection);
        } finally {
            $this->params = $this->queryParamsBackup;
            $this->queryParamsBackup = null;
            $this->queryRender = null;
        }
    }

    public function render(): string
    {
        if ($this->queryParamsBackup !== null) {
            return $this->queryRender;
        }

        return $this->_render();
    }

    public function getDebugQuery(): string
    {
        if ($this->queryParamsBackup === null) {
            return parent::getDebugQuery();
        }

        $paramsBackup = $this->params;
        $queryRenderBackupBackup = $this->queryParamsBackup;
        $queryRenderBackup = $this->queryRender;
        try {
            $this->params = $this->queryParamsBackup;
            $this->queryParamsBackup = null;
            $this->queryRender = null;

            return parent::getDebugQuery();
        } finally {
            $this->params = $paramsBackup;
            $this->queryParamsBackup = $queryRenderBackupBackup;
            $this->queryRender = $queryRenderBackup;
        }
    }

    /// }}}
}
