<?php
declare(strict_types=1);

namespace SenseCMS\LegacyRedirects;

use RuntimeException;

final class RedirectRules
{
    public static function defaults(): array
    {
        return [['aliases'=>['/gabinety','/gabinety/'],'destination'=>'/tlenoterapia/','status'=>301]];
    }

    public static function fromInput(mixed $input): array
    {
        if (!is_array($input) || count($input) > 100) throw new RuntimeException('Use no more than 100 redirect groups.', 422);
        $rules=[]; $seen=[]; $total=0;
        foreach ($input as $row) {
            if (!is_array($row)) throw new RuntimeException('A redirect group is invalid.', 422);
            $aliases=$row['aliases']??'';
            if (is_string($aliases)) $aliases=preg_split('/\r\n|\r|\n/',$aliases) ?: [];
            if (!is_array($aliases)) throw new RuntimeException('Enter one alias path per line.', 422);
            $aliases=array_values(array_filter(array_map(static fn($value):string=>is_string($value)?trim($value):'', $aliases),static fn(string $value):bool=>$value!==''));
            $destination=is_string($row['destination']??null)?trim($row['destination']):'';
            if (!$aliases && $destination==='') continue;
            if (!$aliases || $destination==='') throw new RuntimeException('Every redirect group needs alias paths and a destination.', 422);
            if (count($aliases)>25) throw new RuntimeException('Use no more than 25 aliases in one group.', 422);
            $destination=self::destination($destination);
            $status=(string)($row['status']??'301');
            if (!in_array($status,['301','302'],true)) throw new RuntimeException('Choose a 301 or 302 redirect.', 422);
            $clean=[];
            foreach ($aliases as $alias) {
                $alias=self::source($alias);
                if (isset($seen[$alias])) throw new RuntimeException('Each alias path can appear in only one redirect group.', 422);
                $seen[$alias]=true; $clean[]=$alias;
            }
            $total+=count($clean);
            if ($total>500) throw new RuntimeException('Use no more than 500 alias paths.', 422);
            $rules[]=['aliases'=>$clean,'destination'=>$destination,'status'=>(int)$status];
        }
        self::assertNoLocalCycles($rules);
        return $rules;
    }

    public static function match(array $rules, string $path): ?array
    {
        foreach ($rules as $rule) {
            if (in_array($path,(array)($rule['aliases']??[]),true)) return $rule;
        }
        return null;
    }

    private static function source(string $value): string
    {
        if (strlen($value)>2048 || !str_starts_with($value,'/') || str_starts_with($value,'//') || preg_match('~[\x00-\x20\x7f?#\\\\]~',$value) || preg_match('/%(?![a-f0-9]{2})/i',$value)) {
            throw new RuntimeException('Aliases must be local paths beginning with / and contain no query or fragment.', 422);
        }
        return $value;
    }

    private static function destination(string $value): string
    {
        if ($value==='' || strlen($value)>2048 || preg_match('~[\x00-\x20\x7f\\\\]~',$value)) throw new RuntimeException('Enter a valid local path or HTTPS destination URL.',422);
        if (str_starts_with($value,'/')) {
            if (str_starts_with($value,'//') || parse_url($value,PHP_URL_PATH)===false) throw new RuntimeException('Local destinations must start with a single slash.',422);
            return $value;
        }
        $parts=parse_url($value);
        if (!is_array($parts) || strtolower((string)($parts['scheme']??''))!=='https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('External destinations must be absolute HTTPS URLs without credentials.',422);
        }
        $host=trim((string)$parts['host'],'[]');
        if (!filter_var($host,FILTER_VALIDATE_IP) && !filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)) throw new RuntimeException('Enter a valid HTTPS destination host.',422);
        if (isset($parts['port']) && ((int)$parts['port']<1 || (int)$parts['port']>65535)) throw new RuntimeException('Enter a valid HTTPS destination port.',422);
        return $value;
    }

    private static function assertNoLocalCycles(array $rules): void
    {
        $destinations=[];
        foreach ($rules as $rule) {
            $destination=(string)$rule['destination'];
            if (!str_starts_with($destination,'/')) continue;
            $path=parse_url($destination,PHP_URL_PATH);
            foreach ($rule['aliases'] as $alias) $destinations[$alias]=$path;
        }
        foreach (array_keys($destinations) as $source) {
            $seen=[]; $path=$source;
            while (isset($destinations[$path])) {
                if (isset($seen[$path])) throw new RuntimeException('Local redirects cannot form a loop.',422);
                $seen[$path]=true; $path=$destinations[$path];
            }
        }
    }
}
