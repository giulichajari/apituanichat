<?php
namespace App\Services;

final class AnuncioImage
{
    public const DIRECTORY='/var/lib/tuanichat/anuncios';
    public static function inspect(?array $file): ?array
    {
        if ($file===null || ($file['error']??null)===UPLOAD_ERR_NO_FILE) return null;
        $result=UploadedMedia::validate($file,false);
        // No remote URL or caller-supplied filesystem path is accepted.
        return ['tmp'=>$file['tmp_name'],'mime'=>$result['mime'],'filename'=>$result['filename'],'sha256'=>hash_file('sha256',$file['tmp_name'])];
    }
    public static function store(array $image): string
    {
        if (!is_dir(self::DIRECTORY)||is_link(self::DIRECTORY)||!is_writable(self::DIRECTORY)) throw new \RuntimeException('Private image directory unavailable');
        $name=$image['filename'];
        if (!preg_match('/^[a-f0-9]{40}\.(jpg|png|gif|webp)$/D',$name)) throw new \RuntimeException('Invalid image name');
        $path=self::DIRECTORY.'/'.$name;
        if (file_exists($path)||!move_uploaded_file($image['tmp'],$path)) throw new \RuntimeException('Image persistence failed');
        chmod($path,0640);
        return $name;
    }
    public static function path(string $name): string
    {
        if(!preg_match('/^[a-f0-9]{40}\.(jpg|png|gif|webp)$/D',$name))throw new \RuntimeException('Invalid image name');
        return self::DIRECTORY.'/'.$name;
    }
}
