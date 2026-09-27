<?php
namespace App\Models;

use App\Configs\Database;
use PDO;

final class AnunciosModel
{
    private PDO $db;
    public function __construct(?PDO $db=null) { $this->db=$db??Database::getInstance()->getConnection(); }

    // Called only after the debit, inside the shared purchase transaction.
    public function create(int $userId,array $intent,?string $image,string $slug): int
    {
        if (!$this->db->inTransaction()) throw new \LogicException('Purchase transaction required');
        $stmt=$this->db->prepare("INSERT INTO anuncios_publicidad(usuario_id,contenido,imagen_url,pais_destino,cantidad_personas,precio_centavos,estado,link_compartir) VALUES(?,?,?,?,?,?,'pagado',?)");
        $stmt->execute([$userId,$intent['contenido'],$image,$intent['pais_destino'],$intent['cantidad_personas'],$intent['precio_centavos'],$slug]);
        return (int)$this->db->lastInsertId();
    }
    public function getAllForAdmin(int $page=1,int $limit=25): array
    {
        $stmt=$this->db->prepare('SELECT id,usuario_id,contenido,imagen_url,pais_destino,cantidad_personas,precio_centavos,estado,link_compartir,created_at FROM anuncios_publicidad ORDER BY id DESC LIMIT ? OFFSET ?');
        $stmt->bindValue(1,$limit,PDO::PARAM_INT);$stmt->bindValue(2,($page-1)*$limit,PDO::PARAM_INT);$stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function find(int $id): ?array
    {
        $s=$this->db->prepare('SELECT * FROM anuncios_publicidad WHERE id=?');$s->execute([$id]);return $s->fetch(PDO::FETCH_ASSOC)?:null;
    }
    public function marcarPublicado(int $id): bool
    {
        $s=$this->db->prepare("UPDATE anuncios_publicidad SET estado='publicado' WHERE id=? AND estado='pagado'");$s->execute([$id]);
        // Repeating an admin click is harmless and never debits the wallet again.
        return $s->rowCount()===1 || $this->find($id)!==null;
    }
    public function findPublished(string $slug): ?array
    {
        $s=$this->db->prepare("SELECT id,contenido,imagen_url,pais_destino,estado,link_compartir,created_at FROM anuncios_publicidad WHERE link_compartir=? AND estado='publicado'");
        $s->execute([$slug]);return $s->fetch(PDO::FETCH_ASSOC)?:null;
    }
}
