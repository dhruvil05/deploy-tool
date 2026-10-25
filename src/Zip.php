<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * Minimal standard .zip writer and reader (deflate via zlib), so backups don't need
 * PHP's zip extension, which Laragon ships switched off. Up to 65,535 files and 4 GB.
 */
final class Zip
{
    private const LOCAL_HEADER = 0x04034b50;
    private const CENTRAL_HEADER = 0x02014b50;
    private const END_OF_DIRECTORY = 0x06054b50;
    private const UTF8_NAMES = 0x0800;

    /** @var array<string, array> central directories already read, per zip file */
    private static array $indexes = [];

    /**
     * Writes a zip from name => contents pairs (a generator keeps memory low).
     *
     * @param iterable<string, string> $entries
     */
    public static function create(string $zipFile, iterable $entries): int
    {
        $out = fopen($zipFile, 'wb');
        if ($out === false) {
            throw new \RuntimeException("Could not create $zipFile");
        }
        [$dosTime, $dosDate] = self::dosDateTime(time());
        $central = '';
        $count = 0;
        try {
            foreach ($entries as $name => $data) {
                $name = (string) $name;
                $crc = crc32($data);
                $deflated = gzdeflate($data, 6);
                [$method, $stored] = $deflated !== false && strlen($deflated) < strlen($data) ? [8, $deflated] : [0, $data];
                $offset = ftell($out);
                if ($offset > 0xFFFFFFFF - strlen($stored) - 1024 || $count >= 0xFFFF) {
                    throw new \RuntimeException('The backup is too big for a zip file (over 4 GB or 65,535 files).');
                }
                fwrite($out, pack('VvvvvvVVVvv', self::LOCAL_HEADER, 20, self::UTF8_NAMES, $method, $dosTime, $dosDate,
                    $crc, strlen($stored), strlen($data), strlen($name), 0) . $name);
                fwrite($out, $stored);
                $central .= pack('VvvvvvvVVVvvvvvVV', self::CENTRAL_HEADER, 20, 20, self::UTF8_NAMES, $method, $dosTime, $dosDate,
                    $crc, strlen($stored), strlen($data), strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
                $count++;
            }
            $centralOffset = ftell($out);
            fwrite($out, $central);
            fwrite($out, pack('VvvvvVVv', self::END_OF_DIRECTORY, 0, 0, $count, $count, strlen($central), $centralOffset, 0));
        } finally {
            fclose($out);
        }
        unset(self::$indexes[$zipFile]);
        return $count;
    }

    /** @return array<string, array{method: int, crc: int, packed: int, size: int, offset: int}> */
    public static function index(string $zipFile): array
    {
        if (isset(self::$indexes[$zipFile])) {
            return self::$indexes[$zipFile];
        }
        $in = fopen($zipFile, 'rb');
        if ($in === false) {
            throw new \RuntimeException("Could not open $zipFile");
        }
        try {
            $fileSize = fstat($in)['size'];
            $tailSize = min($fileSize, 65557);
            fseek($in, $fileSize - $tailSize);
            $tail = (string) fread($in, $tailSize);
            $end = strrpos($tail, pack('V', self::END_OF_DIRECTORY));
            if ($end === false) {
                throw new \RuntimeException(basename($zipFile) . ' is not a valid zip file.');
            }
            $eocd = unpack('Vsig/vdisk/vcdisk/ventries/vtotal/Vsize/Voffset', substr($tail, $end, 20));
            fseek($in, $eocd['offset']);
            $directory = $eocd['size'] > 0 ? (string) fread($in, $eocd['size']) : '';
        } finally {
            fclose($in);
        }

        $entries = [];
        $pos = 0;
        for ($i = 0; $i < $eocd['total']; $i++) {
            $h = unpack('Vsig/vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vpacked/Vsize/vname/vextra/vcomment/vdisk/vint/Vext/Voffset', substr($directory, $pos, 46));
            if ($h['sig'] !== self::CENTRAL_HEADER) {
                throw new \RuntimeException(basename($zipFile) . ' has a damaged file list.');
            }
            $name = substr($directory, $pos + 46, $h['name']);
            $entries[$name] = ['method' => $h['method'], 'crc' => $h['crc'], 'packed' => $h['packed'], 'size' => $h['size'], 'offset' => $h['offset']];
            $pos += 46 + $h['name'] + $h['extra'] + $h['comment'];
        }
        return self::$indexes[$zipFile] = $entries;
    }

    /** One file's contents, or null when it isn't in the zip. Checks the CRC. */
    public static function read(string $zipFile, string $name): ?string
    {
        $entry = self::index($zipFile)[$name] ?? null;
        if ($entry === null) {
            return null;
        }
        $in = fopen($zipFile, 'rb');
        try {
            fseek($in, $entry['offset']);
            $h = unpack('Vsig/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vpacked/Vsize/vname/vextra', (string) fread($in, 30));
            if ($h['sig'] !== self::LOCAL_HEADER) {
                throw new \RuntimeException("$name is damaged in " . basename($zipFile) . '.');
            }
            fseek($in, $h['name'] + $h['extra'], SEEK_CUR);
            $stored = $entry['packed'] > 0 ? (string) fread($in, $entry['packed']) : '';
        } finally {
            fclose($in);
        }
        $data = match ($entry['method']) {
            0 => $stored,
            8 => gzinflate($stored),
            default => throw new \RuntimeException("$name uses an unsupported zip method."),
        };
        if ($data === false || crc32($data) !== $entry['crc']) {
            throw new \RuntimeException("$name is damaged in " . basename($zipFile) . '.');
        }
        return $data;
    }

    /** @return array{0: int, 1: int} MS-DOS time and date */
    private static function dosDateTime(int $timestamp): array
    {
        $t = getdate($timestamp);
        return [
            ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2),
            (max($t['year'], 1980) - 1980 << 9) | ($t['mon'] << 5) | $t['mday'],
        ];
    }
}
