<?php
namespace App\Services;

final class UploadedMedia
{
    public static function validate(array $file, bool $allowVideo = false): array
    {
        if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null)
            || !is_uploaded_file($file['tmp_name'])) {
            throw new \InvalidArgumentException('Archivo no recibido correctamente');
        }
        return self::inspect($file['tmp_name'], $file['name'] ?? '', $allowVideo);
    }

    // Separado de validate para comprobar contenidos sintéticos sin simular uploads HTTP.
    public static function inspect(string $path, $name, bool $allowVideo = false): array
    {
        if (!is_string($name) || !is_file($path)) {
            throw new \InvalidArgumentException('Archivo inválido');
        }
        $limit = $allowVideo ? 50 * 1024 * 1024 : 5 * 1024 * 1024;
        $bytes = filesize($path);
        if ($bytes === false || $bytes < 1 || $bytes > $limit) {
            throw new \InvalidArgumentException($allowVideo ? 'Máximo 50 MB por estado' : 'Máximo 5 MB por avatar');
        }
        $formats = [
            'image/jpeg' => ['jpg','jpeg'], 'image/png' => ['png'],
            'image/gif' => ['gif'], 'image/webp' => ['webp'],
        ];
        if ($allowVideo) {
            $formats['video/mp4'] = ['mp4'];
            $formats['video/quicktime'] = ['mov'];
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset($formats[$mime]) || !in_array($extension, $formats[$mime], true)) {
            throw new \InvalidArgumentException('Formato o extensión no permitido');
        }
        // Rechazar etiquetas PHP explícitas incrustadas; no sustituye aislamiento de uploads.
        $handle = fopen($path, 'rb');
        if (!$handle) throw new \RuntimeException('No se pudo leer el archivo');
        try {
            $tail = '';
            while (!feof($handle)) {
                $chunk = fread($handle, 65536);
                if ($chunk === false) throw new \RuntimeException('No se pudo leer el archivo');
                $chunk = $tail . $chunk;
                if (preg_match('/<\?php\s/i', $chunk)) throw new \InvalidArgumentException('Contenido no permitido');
                $tail = substr($chunk, -8);
            }
        } finally { fclose($handle); }
        $type = str_starts_with($mime, 'image/') ? 'image' : 'video';
        if ($type === 'image') {
            $info = @getimagesize($path);
            if (!$info || ($info['mime'] ?? '') !== $mime || $info[0] < 1 || $info[1] < 1
                || $info[0] > 8192 || $info[1] > 8192 || $info[0] * $info[1] > 24000000) {
                throw new \InvalidArgumentException('Imagen inválida o dimensiones excesivas');
            }
        }
        return ['type'=>$type, 'mime'=>$mime, 'filename'=>bin2hex(random_bytes(20)).'.'.$formats[$mime][0]];
    }
}
