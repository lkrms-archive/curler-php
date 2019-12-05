<?php

namespace Lkrms\Curler;

class CurlerHeader
{
    private $Headers = array('user-agent' => 'User-Agent:Curler PHP (https://github.com/lkrms/curler-php)');

    public function SetHeader($name, $value)
    {
        $name   = trim($name);
        $value  = trim($value);

        // HTTP headers are case-insensitive, so make sure we don't end up with duplicates
        $this->Headers [strtolower($name)] = "{$name}:{$value}";
    }

    public function GetHeaders()
    {
        return array_values($this->Headers);
    }
}
