<?php

declare(strict_types=1);

namespace App\Services\Web;

/**
 * Bot text → safe HTML for the browser `/play` screen (web-bridge-p1).
 *
 * Covers the three parse modes the bot sends: legacy `Markdown`, `HTML`, `MarkdownV2`.
 * Everything is escaped first; only `b i u s code pre`, `a href` (http/https) and `<br>`
 * for newlines come back. Unknown entities stay as escaped text. Never throws: any failure
 * degrades to escaped plain text. Never truncates (caption > 1024 renders in full).
 */
final class TelegramMarkupRenderer
{
    /** HTML-mode tag → output tag (allow-list). */
    private const HTML_ALLOWED = [
        'b' => 'b', 'strong' => 'b',
        'i' => 'i', 'em' => 'i',
        'u' => 'u', 'ins' => 'u',
        's' => 's', 'strike' => 's', 'del' => 's',
        'code' => 'code', 'pre' => 'pre',
        'a' => 'a',
    ];

    /** Telegram tags with no web equivalent: tag dropped, content kept. */
    private const HTML_STRIPPED = ['tg-spoiler', 'span', 'blockquote', 'tg-emoji'];

    public static function toHtml(?string $text, ?string $parseMode): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        try {
            return match (strtolower(trim((string) $parseMode))) {
                'html'       => self::html($text),
                'markdown'   => self::markdown($text, false),
                'markdownv2' => self::markdown($text, true),
                default      => self::plain($text),
            };
        } catch (\Throwable) {
            return self::plain($text);
        }
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private static function plain(string $s): string
    {
        return str_replace("\n", '<br>', self::esc($s));
    }

    private static function safeUrl(string $url): ?string
    {
        $url = trim($url);
        if (preg_match('~^https?://[^\s<>"\'`\x00-\x1f\x7f]+$~i', $url) !== 1) {
            return null;
        }

        return $url;
    }

    private static function link(string $url, string $innerHtml): string
    {
        return '<a href="' . self::esc($url) . '" rel="nofollow noopener noreferrer" target="_blank">'
            . $innerHtml . '</a>';
    }

    // ---------------------------------------------------------------- Markdown / MarkdownV2

    private static function markdown(string $s, bool $v2): string
    {
        $out = '';
        $buf = '';
        $len = strlen($s);
        $i   = 0;

        // v2: nested entities; legacy: flat, no escapes (Telegram legacy semantics).
        $delims = $v2
            ? ['||' => '', '__' => 'u', '*' => 'b', '_' => 'i', '~' => 's']
            : ['*' => 'b', '_' => 'i'];

        while ($i < $len) {
            $c = $s[$i];

            if ($v2 && $c === '\\' && $i + 1 < $len) {
                $buf .= $s[$i + 1];
                $i += 2;
                continue;
            }

            if ($c === '`') {
                if (substr($s, $i, 3) === '```') {
                    $end = self::findClose($s, '```', $i + 3, $v2);
                    if ($end !== null) {
                        $inner = (string) preg_replace('~^[A-Za-z0-9_+#-]*\n~', '', substr($s, $i + 3, $end - $i - 3));
                        $out  .= self::plain($buf) . '<pre>' . self::esc(self::unescapeV2($inner, $v2)) . '</pre>';
                        $buf   = '';
                        $i     = $end + 3;
                        continue;
                    }
                }
                $end = self::findClose($s, '`', $i + 1, $v2);
                if ($end !== null && $end > $i + 1) {
                    $inner = substr($s, $i + 1, $end - $i - 1);
                    $out  .= self::plain($buf) . '<code>' . self::esc(self::unescapeV2($inner, $v2)) . '</code>';
                    $buf   = '';
                    $i     = $end + 1;
                    continue;
                }
            }

            if ($c === '[') {
                $re = $v2
                    ? '~\G\[((?:\\\\.|[^\]\\\\])*)\]\(((?:\\\\.|[^)\\\\])*)\)~s'
                    : '~\G\[([^\]]*)\]\(([^)\s]*)\)~';
                if (preg_match($re, $s, $m, 0, $i) === 1) {
                    $textHtml = $v2 ? self::markdown($m[1], true) : self::plain($m[1]);
                    $url      = self::safeUrl(self::unescapeV2($m[2], $v2));
                    $out     .= self::plain($buf) . ($url !== null ? self::link($url, $textHtml) : $textHtml);
                    $buf      = '';
                    $i       += strlen($m[0]);
                    continue;
                }
            }

            foreach ($delims as $d => $tag) {
                $dl = strlen($d);
                if (substr($s, $i, $dl) !== $d) {
                    continue;
                }
                $end = self::findClose($s, $d, $i + $dl, $v2);
                if ($end === null || $end === $i + $dl) {
                    break;
                }
                $inner     = substr($s, $i + $dl, $end - $i - $dl);
                $innerHtml = $v2 ? self::markdown($inner, true) : self::plain($inner);
                $out      .= self::plain($buf) . ($tag === '' ? $innerHtml : "<{$tag}>{$innerHtml}</{$tag}>");
                $buf       = '';
                $i         = $end + $dl;
                continue 2;
            }

            $buf .= $c;
            $i++;
        }

        return $out . self::plain($buf);
    }

    /** Next position of $d at or after $from; in v2 skips backslash-escaped characters. */
    private static function findClose(string $s, string $d, int $from, bool $v2): ?int
    {
        if (! $v2) {
            $p = strpos($s, $d, $from);

            return $p === false ? null : $p;
        }
        $len = strlen($s);
        $dl  = strlen($d);
        for ($j = $from; $j < $len; $j++) {
            if ($s[$j] === '\\') {
                $j++;
                continue;
            }
            if ($d === '_' && substr($s, $j, 2) === '__') {
                $j++;
                continue;
            }
            if (substr($s, $j, $dl) === $d) {
                return $j;
            }
        }

        return null;
    }

    private static function unescapeV2(string $s, bool $v2): string
    {
        return $v2 ? (string) preg_replace('~\\\\(.)~s', '$1', $s) : $s;
    }

    // ---------------------------------------------------------------- HTML

    private static function html(string $s): string
    {
        $re = '~<\s*(/?)\s*([a-zA-Z][a-zA-Z0-9-]*)((?:[^<>"\']|"[^"]*"|\'[^\']*\')*)>~';
        if (preg_match_all($re, $s, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            return self::plain($s);
        }

        $out   = '';
        $stack = [];
        $pos   = 0;
        foreach ($all as $m) {
            [$raw, $at] = $m[0];
            $out .= self::htmlText(substr($s, $pos, $at - $pos), in_array('pre', $stack, true));
            $pos  = $at + strlen($raw);

            $closing = $m[1][0] === '/';
            $name    = strtolower($m[2][0]);

            if (isset(self::HTML_ALLOWED[$name])) {
                $tag = self::HTML_ALLOWED[$name];
                if ($closing) {
                    $idx = array_search($tag, $stack, true);
                    if ($idx !== false) {
                        while (count($stack) > $idx) {
                            $out .= '</' . array_pop($stack) . '>';
                        }
                    }
                    continue;
                }
                if ($tag === 'a') {
                    $url = self::htmlHref($m[3][0]);
                    if ($url === null) {
                        continue; // unsafe/absent href: drop tag, keep text
                    }
                    $out .= '<a href="' . self::esc($url) . '" rel="nofollow noopener noreferrer" target="_blank">';
                } else {
                    $out .= '<' . $tag . '>';
                }
                $stack[] = $tag;
                continue;
            }

            if (in_array($name, self::HTML_STRIPPED, true)) {
                continue;
            }

            $out .= self::esc($raw); // anything else: inert text
        }
        $out .= self::htmlText(substr($s, $pos), in_array('pre', $stack, true));
        while ($stack !== []) {
            $out .= '</' . array_pop($stack) . '>';
        }

        return $out;
    }

    /** HTML-mode text: Telegram's own entities (&lt; &amp; …) render as characters, not re-escaped. */
    private static function htmlText(string $t, bool $inPre): string
    {
        $e = htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8', false);

        return $inPre ? $e : str_replace("\n", '<br>', $e);
    }

    private static function htmlHref(string $attrs): ?string
    {
        if (preg_match('~\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))~i', $attrs, $h) !== 1) {
            return null;
        }
        $v = $h[1] . ($h[2] ?? '') . ($h[3] ?? '');

        return self::safeUrl(html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
