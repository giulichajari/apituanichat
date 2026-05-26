<?php

namespace App\Models;

use App\Configs\Database;
use PDO;

class DriverApplicationModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function create(array $data): ?int
    {
        $stmt = $this->db->prepare("
            INSERT INTO driver_applications
            (user_id, full_name, email, phone, form_data, documents, signature_path, signature_name, signature_date, status, ip_address)
            VALUES
            (:user_id, :full_name, :email, :phone, :form_data, :documents, :signature_path, :signature_name, :signature_date, 'pending', :ip_address)
        ");

        $ok = $stmt->execute([
            ':user_id' => $data['user_id'] ?? null,
            ':full_name' => $data['full_name'],
            ':email' => $data['email'],
            ':phone' => $data['phone'],
            ':form_data' => json_encode($data['form_data'], JSON_UNESCAPED_UNICODE),
            ':documents' => json_encode($data['documents'] ?? [], JSON_UNESCAPED_UNICODE),
            ':signature_path' => $data['signature_path'] ?? null,
            ':signature_name' => $data['signature_name'] ?? null,
            ':signature_date' => $data['signature_date'] ?? null,
            ':ip_address' => $data['ip_address'] ?? null,
        ]);

        return $ok ? (int) $this->db->lastInsertId() : null;
    }

    public function updateDocuments(int $id, array $documents): bool
    {
        $stmt = $this->db->prepare("UPDATE driver_applications SET documents = :documents WHERE id = :id");
        return $stmt->execute([
            ':documents' => json_encode($documents, JSON_UNESCAPED_UNICODE),
            ':id' => $id,
        ]);
    }

    public function updateSignature(int $id, string $signaturePath): bool
    {
        $stmt = $this->db->prepare("UPDATE driver_applications SET signature_path = :path WHERE id = :id");
        return $stmt->execute([':path' => $signaturePath, ':id' => $id]);
    }

    public function findAll(?string $status = null): array
    {
        if ($status) {
            $stmt = $this->db->prepare("
                SELECT id, user_id, full_name, email, phone, status, created_at, updated_at
                FROM driver_applications
                WHERE status = :status
                ORDER BY created_at DESC
            ");
            $stmt->execute([':status' => $status]);
        } else {
            $stmt = $this->db->query("
                SELECT id, user_id, full_name, email, phone, status, created_at, updated_at
                FROM driver_applications
                ORDER BY created_at DESC
            ");
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM driver_applications WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $row['form_data'] = json_decode($row['form_data'] ?? '{}', true) ?: [];
        $row['documents'] = json_decode($row['documents'] ?? '[]', true) ?: [];

        return $row;
    }

    public function updateStatus(int $id, string $status): bool
    {
        if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
            return false;
        }

        $stmt = $this->db->prepare("UPDATE driver_applications SET status = :status WHERE id = :id");
        return $stmt->execute([':status' => $status, ':id' => $id]);
    }

    public function getDashboardStats(): array
    {
        $stats = [
            'activeDrivers' => 0,
            'driversOnline' => 0,
            'pendingApplications' => 0,
            'approvedApplications' => 0,
            'rejectedApplications' => 0,
            'earningsToday' => 0,
            'totalRides' => 0,
        ];

        try {
            $stmt = $this->db->query("SELECT COUNT(*) AS c FROM drivers WHERE TRIM(COALESCE(name, '')) <> ''");
            $stats['activeDrivers'] = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
        } catch (\Throwable $e) {
            // tabla drivers puede no existir en algunos entornos
        }

        try {
            $stmt = $this->db->query("SELECT COUNT(*) AS c FROM drivers WHERE is_available = 1");
            $stats['driversOnline'] = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
        } catch (\Throwable $e) {
        }

        try {
            $stmt = $this->db->query("
                SELECT status, COUNT(*) AS c
                FROM driver_applications
                GROUP BY status
            ");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = match ($row['status']) {
                    'pending' => 'pendingApplications',
                    'approved' => 'approvedApplications',
                    'rejected' => 'rejectedApplications',
                    default => null,
                };
                if ($key) {
                    $stats[$key] = (int) $row['c'];
                }
            }
        } catch (\Throwable $e) {
        }

        try {
            $stmt = $this->db->query("
                SELECT COALESCE(SUM(estimated_fare), 0) AS total
                FROM ride_requests
                WHERE DATE(created_at) = CURDATE()
            ");
            $stats['earningsToday'] = (float) ($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

            $stmt = $this->db->query("SELECT COUNT(*) AS c FROM ride_requests");
            $stats['totalRides'] = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
        } catch (\Throwable $e) {
        }

        if ($stats['activeDrivers'] === 0 && $stats['approvedApplications'] > 0) {
            $stats['activeDrivers'] = $stats['approvedApplications'];
        }

        return $stats;
    }

    public function getChartData(): array
    {
        $applicationsByStatus = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
        $applicationsPerDay = [];
        $ridesPerDay = [];
        $availabilitySplit = ['online' => 0, 'offline' => 0];

        try {
            $stmt = $this->db->query("
                SELECT status, COUNT(*) AS c FROM driver_applications GROUP BY status
            ");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (isset($applicationsByStatus[$row['status']])) {
                    $applicationsByStatus[$row['status']] = (int) $row['c'];
                }
            }

            $stmt = $this->db->query("
                SELECT DATE(created_at) AS day, COUNT(*) AS c
                FROM driver_applications
                WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                GROUP BY DATE(created_at)
                ORDER BY day ASC
            ");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $applicationsPerDay[] = ['date' => $row['day'], 'count' => (int) $row['c']];
            }
        } catch (\Throwable $e) {
        }

        try {
            $stmt = $this->db->query("
                SELECT DATE(created_at) AS day, COUNT(*) AS c
                FROM ride_requests
                WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                GROUP BY DATE(created_at)
                ORDER BY day ASC
            ");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $ridesPerDay[] = ['date' => $row['day'], 'count' => (int) $row['c']];
            }
        } catch (\Throwable $e) {
        }

        try {
            $stmt = $this->db->query("
                SELECT
                    SUM(CASE WHEN is_available = 1 THEN 1 ELSE 0 END) AS online,
                    SUM(CASE WHEN is_available = 0 OR is_available IS NULL THEN 1 ELSE 0 END) AS offline
                FROM drivers
                WHERE TRIM(COALESCE(name, '')) <> ''
            ");
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $availabilitySplit['online'] = (int) ($row['online'] ?? 0);
            $availabilitySplit['offline'] = (int) ($row['offline'] ?? 0);
        } catch (\Throwable $e) {
        }

        return [
            'applicationsByStatus' => $applicationsByStatus,
            'applicationsPerDay' => $applicationsPerDay,
            'ridesPerDay' => $ridesPerDay,
            'availabilitySplit' => $availabilitySplit,
        ];
    }

    public function getApprovedDriversWithStats(int $limit = 50): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    da.id AS application_id,
                    da.user_id,
                    da.full_name,
                    da.email,
                    da.phone,
                    da.status,
                    da.form_data,
                    da.created_at,
                    da.updated_at,
                    d.is_available,
                    d.car_model,
                    d.license_plate,
                    d.location,
                    (SELECT COUNT(*) FROM ride_requests rr WHERE rr.driver_id = da.user_id) AS total_rides,
                    (SELECT COALESCE(SUM(rr.estimated_fare), 0) FROM ride_requests rr WHERE rr.driver_id = da.user_id) AS total_earnings
                FROM driver_applications da
                LEFT JOIN drivers d ON d.user_id = da.user_id
                WHERE da.status = 'approved'
                ORDER BY da.updated_at DESC
                LIMIT :lim
            ");
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }

        return array_map(function ($row) {
            $formData = json_decode($row['form_data'] ?? '{}', true) ?: [];
            $vehicle = $formData['vehicle'] ?? [];
            $carModel = trim($row['car_model'] ?? '');
            if ($carModel === '' && !empty($vehicle)) {
                $carModel = trim(($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? '') . ' ' . ($vehicle['year'] ?? ''));
            }
            unset($row['form_data']);
            $row['vehicle_label'] = $carModel ?: '—';
            $row['is_available'] = (int) ($row['is_available'] ?? 0);
            $row['total_rides'] = (int) ($row['total_rides'] ?? 0);
            $row['total_earnings'] = (float) ($row['total_earnings'] ?? 0);
            return $row;
        }, $rows);
    }
}
