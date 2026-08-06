<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\HAL;

/**
 * CcuXmlRpc — minimaler, SELBST-ENTHALTENER XML-RPC-Client fuer den HomeMatic
 * BidCos-/HmIP-Dienst der CCU. Bewusst OHNE ext-xmlrpc und OHNE Abhaengigkeit
 * von Legacy-Bibliotheken (Veroeffentlichbarkeit, MIT): Request-XML wird von Hand
 * gebaut, die Antwort mit SimpleXML rekursiv dekodiert.
 *
 * Deckt genau die zwei Aufrufe ab, die die Heizungs-HAL fuer den geraeteseitigen
 * Wochenplan (scheduleMode 'device') braucht:
 *   getParamset(<address>, "MASTER")        -> Wochenprofil lesen
 *   putParamset(<address>, "MASTER", {..})  -> Wochenprofil schreiben
 *
 * Adressierung spiegelt die bewaehrte Praxis: "<BaseSerial>:<channel>" / MASTER.
 * Ports: BidCos (klassisch) 2001, HmIP 2010.
 */
final class CcuXmlRpc
{
    /** Liest ein MASTER-Paramset. Rueckgabe: name=>wert oder null bei Fehler. */
    public static function getParamset(string $host, int $port, string $address, string $set = 'MASTER', int $timeoutMs = 4000): ?array
    {
        $xml  = self::buildCall('getParamset', [
            ['string' => $address],
            ['string' => $set],
        ]);
        $resp = self::post($host, $port, $xml, $timeoutMs);
        if ($resp === null) {
            return null;
        }
        $val = self::decodeResponse($resp);
        return is_array($val) ? $val : null;
    }

    /**
     * Schreibt Werte in ein MASTER-Paramset. $params: name=>['type'=>'double'|'int'|'string','value'=>..].
     * Rueckgabe: true bei Erfolg (kein XML-RPC-Fault), sonst false.
     */
    public static function putParamset(string $host, int $port, string $address, array $params, string $set = 'MASTER', int $timeoutMs = 8000): bool
    {
        $members = '';
        foreach ($params as $name => $spec) {
            $type  = $spec['type'] ?? 'string';
            $value = $spec['value'] ?? '';
            $members .= '<member><name>' . self::esc((string) $name) . '</name><value>'
                . self::scalarXml($type, $value) . '</value></member>';
        }
        $xml = self::buildCall('putParamset', [
            ['string' => $address],
            ['string' => $set],
            ['raw' => '<struct>' . $members . '</struct>'],
        ]);
        $resp = self::post($host, $port, $xml, $timeoutMs);
        if ($resp === null) {
            return false;
        }
        return strpos($resp, '<fault>') === false; // Fault -> false
    }

    // ------------------------------------------------------------------
    // intern
    // ------------------------------------------------------------------

    /** @param array<int,array<string,mixed>> $params je Param ['string'=>..]|['int'=>..]|['raw'=>xml] */
    private static function buildCall(string $method, array $params): string
    {
        $ps = '';
        foreach ($params as $p) {
            if (isset($p['raw'])) {
                $inner = (string) $p['raw'];
            } elseif (array_key_exists('string', $p)) {
                $inner = '<string>' . self::esc((string) $p['string']) . '</string>';
            } elseif (array_key_exists('int', $p)) {
                $inner = '<i4>' . (int) $p['int'] . '</i4>';
            } elseif (array_key_exists('double', $p)) {
                $inner = '<double>' . self::dbl((float) $p['double']) . '</double>';
            } else {
                $inner = '<string></string>';
            }
            $ps .= '<param><value>' . $inner . '</value></param>';
        }
        return '<?xml version="1.0" encoding="ISO-8859-1"?>'
            . '<methodCall><methodName>' . self::esc($method) . '</methodName>'
            . '<params>' . $ps . '</params></methodCall>';
    }

    private static function scalarXml(string $type, $value): string
    {
        switch ($type) {
            case 'double':
            case 'float':
                return '<double>' . self::dbl((float) $value) . '</double>';
            case 'int':
            case 'i4':
                return '<i4>' . (int) $value . '</i4>';
            case 'boolean':
            case 'bool':
                return '<boolean>' . ((int) (bool) $value) . '</boolean>';
            default:
                return '<string>' . self::esc((string) $value) . '</string>';
        }
    }

    private static function post(string $host, int $port, string $body, int $timeoutMs): ?string
    {
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: text/xml\r\nContent-Length: " . strlen($body) . "\r\n",
            'content'       => $body,
            'timeout'       => max(1, (int) ($timeoutMs / 1000)),
            'ignore_errors' => true,
        ]]);
        $url = 'http://' . $host . ':' . $port . '/';
        $r   = @file_get_contents($url, false, $ctx);
        return ($r === false) ? null : $r;
    }

    /** Dekodiert die <methodResponse> und liefert den ersten Param-Wert. */
    private static function decodeResponse(string $xml)
    {
        $prev = libxml_use_internal_errors(true);
        $doc  = simplexml_load_string($xml);
        libxml_use_internal_errors($prev);
        if ($doc === false || isset($doc->fault) || !isset($doc->params->param->value)) {
            return null;
        }
        return self::decodeValue($doc->params->param->value);
    }

    /** Rekursiver Value-Dekoder (struct/array/scalar). */
    private static function decodeValue(\SimpleXMLElement $value)
    {
        $children = $value->children();
        if (count($children) === 0) {
            return (string) $value; // <value> ohne Kind-Element => String
        }
        $node = $children[0];
        switch ($node->getName()) {
            case 'struct':
                $out = [];
                foreach ($node->member as $m) {
                    $out[(string) $m->name] = isset($m->value) ? self::decodeValue($m->value) : null;
                }
                return $out;
            case 'array':
                $out = [];
                if (isset($node->data)) {
                    foreach ($node->data->value as $v) {
                        $out[] = self::decodeValue($v);
                    }
                }
                return $out;
            case 'i4':
            case 'int':
                return (int) $node;
            case 'double':
                return (float) $node;
            case 'boolean':
                return ((string) $node) === '1';
            default: // string, dateTime.iso8601, base64
                return (string) $node;
        }
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function dbl(float $f): string
    {
        return rtrim(rtrim(number_format($f, 6, '.', ''), '0'), '.') ?: '0';
    }
}
