<?php

declare(strict_types=1);

namespace Atk4\Data\Persistence\Sql\Oracle;

use Doctrine\DBAL\Result as DbalResult;
use Atk4\Data\Persistence;

trait ExpressionTrait
{
    // {{{ Oracle has broken support for CLOB/BLOB parameters, so convert string parameters to string literals

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
            $newParams = $this->params;
            $i = 0;
            $j = 0;
            $this->queryRender = preg_replace_callback(
                '~(?:\'(?:\'\'|\\\\\'|[^\'])*\')?+\K(?:\?|:\w+)~s',
                function ($matches) use (&$newParams, &$i, &$j) {
                    $val = $this->params[$matches[0] === '?' ? ++$j : $matches[0]];

                    if (is_string($val)) {


//                        $isBinary = false;
//                        $dummyPersistence = new Persistence\Sql($this->connection);
//                        if (\Closure::bind(fn () => $dummyPersistence->binaryTypeValueIsEncoded($val), null, Persistence\Sql::class)()) {
//                            $val = \Closure::bind(fn () => $dummyPersistence->binaryTypeValueDecode($val), null, Persistence\Sql::class)();
//                            $isBinary = true;
//                        }
//
//
//
//                        if ($isBinary) {
//                            return 'rawtohex(\'' . str_replace('\'', '\'\'', $val) . '\')'; // TODO check escaping
//                        }

                        return '\'' . str_replace('\'', '\'\'', $val) . '\''; // TODO check escaping
                    }

                    $newParams[$matches[0] === '?' ? ++$i : $matches[0]] = $val;

                    return $matches[0];
                },
                parent::render()
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

        return parent::render();
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
