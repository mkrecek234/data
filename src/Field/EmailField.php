<?php

declare(strict_types=1);

namespace Atk4\Data\Field;

use Atk4\Data\Field;
use Atk4\Data\ValidationException;

/**
 * Stores valid email as per configuration.
 *
 * Usage:
 *  $user->addField('email', [EmailField::class]);
 *  $user->addField('email_mx_check', [EmailField::class, 'dns_check' => true]);
 *  $user->addField('email_with_name', [EmailField::class, 'allow_name' => true]);
 */
class EmailField extends Field
{
    /** @var bool Enable lookup for MX record for email addresses stored */
    public $dns_check = false;

    /** @var bool Allow display name as per RFC2822, eg. format like "Romans <me@example.com>" */
    public $allow_name = false;

    public function normalize($value)
    {
        $value = parent::normalize($value);
        if ($value === null) {
            return $value;
        }

        $email = trim($value);
        if ($this->allow_name) {
            $email = preg_replace('/^[^<]*<([^>]*)>/', '\1', $email);
        }

        if (strpos($email, '@') === false) {
            throw new ValidationException([$this->short_name => 'Email address does not have domain'], $this->getOwner());
        }

        [$user, $domain] = explode('@', $email, 2);
        $domain = idn_to_ascii($domain, \IDNA_DEFAULT, \INTL_IDNA_VARIANT_UTS46); // always convert domain to ASCII

        if (!filter_var($user . '@' . $domain, \FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException([$this->short_name => 'Email address format is invalid'], $this->getOwner());
        }

        if ($this->dns_check) {
            if (!$this->hasAnyDnsRecord($domain)) {
                throw new ValidationException([$this->short_name => 'Email address domain does not exist'], $this->getOwner());
            }
        }

        return parent::normalize($value);
    }

    private function hasAnyDnsRecord(string $domain, array $types = ['MX', 'A', 'AAAA', 'CNAME']): bool
    {
        foreach (array_unique(array_map('strtoupper', $types)) as $t) {
            $dnsConsts = [
                'MX' => \DNS_MX,
                'A' => \DNS_A,
                'AAAA' => \DNS_AAAA,
                'CNAME' => \DNS_CNAME,
            ];

            $records = @dns_get_record($domain . '.', $dnsConsts[$t]);
            if ($records === false) { // retry once on failure
                $records = dns_get_record($domain . '.', $dnsConsts[$t]);
            }
            if ($records !== false && count($records) > 0) {
                return true;
            }
        }

        return false;
    }
}
