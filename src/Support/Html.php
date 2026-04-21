<?php
declare(strict_types=1);

namespace NDASA\Support;

final class Html
{
    public static function h(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
