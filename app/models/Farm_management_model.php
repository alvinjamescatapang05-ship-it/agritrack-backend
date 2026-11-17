<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/Audit_logger.php';

class Farm_management_model extends Model
{
    public function __construct()
    {
        parent::__construct();
        lava_instance()->call->database();
    }

    public function list_farms(array $user)
    {
        [$scope, $params] = $this->scope($user, 'farmers');
        $where = array_merge(['farms.deleted_at IS NULL', 'farmers.deleted_at IS NULL'], $scope);
        return $this->db->raw(
            'SELECT farms.id, farms.barangay_id, farms.farmer_id, farms.farm_name,
                    farms.location_description, farms.latitude, farms.longitude,
                    farms.area_hectares, farms.ownership_type, farms.is_sample,
                    barangays.name AS barangay_name,
                    CONCAT(farmers.first_name, " ", farmers.last_name) AS farmer_name
             FROM farms
             INNER JOIN farmers ON farmers.id = farms.farmer_id
                AND farmers.barangay_id = farms.barangay_id
             INNER JOIN barangays ON barangays.id = farms.barangay_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY barangays.name, farmers.last_name, farms.id DESC',
            $params
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function farm_options(array $user)
    {
        [$scope, $params] = $this->scope($user, 'farmers');
        return [
            'farmers' => $this->db->raw(
                'SELECT farmers.id, farmers.barangay_id, farmers.first_name,
                        farmers.last_name, barangays.name AS barangay_name
                 FROM farmers
                 INNER JOIN barangays ON barangays.id = farmers.barangay_id
                 WHERE farmers.is_active = 1 AND farmers.deleted_at IS NULL
                   AND barangays.is_active = 1 AND barangays.deleted_at IS NULL
                   AND ' . implode(' AND ', $scope ?: ['1 = 1']) . '
                 ORDER BY farmers.last_name, farmers.first_name',
                $params
            )->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    public function create_farm(array $user, array $data)
    {
        $farmer = $this->find_farmer_in_scope($user, $data['farmerId']);
        if (!$farmer) {
            throw new InvalidArgumentException('Choose an active farmer in your authorized data scope.');
        }

        $this->db->transaction();
        try {
            $this->db->raw(
                'INSERT INTO farms
                    (barangay_id, farmer_id, farm_name, location_description,
                     latitude, longitude, area_hectares, ownership_type)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    (int) $farmer['barangay_id'],
                    (int) $farmer['id'],
                    $data['farmName'],
                    $data['locationDescription'],
                    $data['latitude'],
                    $data['longitude'],
                    $data['areaHectares'],
                    $data['ownershipType'],
                ]
            );
            $id = (int) $this->db->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
            (new Audit_logger($this->db))->record(
                (int) $user['id'],
                $user['role_slug'],
                (int) $farmer['barangay_id'],
                'create',
                'farms',
                $id,
                null,
                ['farmer_id' => (int) $farmer['id'], 'farm_name' => $data['farmName']]
            );
            $this->db->commit();
            return $id;
        } catch (Throwable $exception) {
            $this->db->roll_back();
            throw $exception;
        }
    }

    public function archive_farm(array $user, $farmId)
    {
        [$scope, $params] = $this->scope($user, 'farms');
        $farm = $this->db->raw(
            'SELECT farms.id, farms.barangay_id
             FROM farms WHERE farms.id = ? AND farms.deleted_at IS NULL'
             . ($scope ? ' AND ' . implode(' AND ', $scope) : '') . ' LIMIT 1',
            array_merge([$farmId], $params)
        )->fetch(PDO::FETCH_ASSOC);
        if (!$farm) {
            return false;
        }

        $inUse = $this->db->raw(
            'SELECT
                (SELECT COUNT(*) FROM rice_plantings WHERE farm_id = ? AND deleted_at IS NULL) +
                (SELECT COUNT(*) FROM crop_plantings WHERE farm_id = ? AND deleted_at IS NULL) +
                (SELECT COUNT(*) FROM livestock WHERE farm_id = ? AND deleted_at IS NULL) +
                (SELECT COUNT(*) FROM production WHERE farm_id = ?) +
                (SELECT COUNT(*) FROM harvests WHERE farm_id = ? AND deleted_at IS NULL)',
            [$farmId, $farmId, $farmId, $farmId, $farmId]
        )->fetchColumn();
        if ((int) $inUse > 0) {
            throw new InvalidArgumentException('This farm has linked planting, livestock, production, or harvest records and cannot be archived.');
        }

        $this->db->transaction();
        try {
            $this->db->raw(
                'UPDATE farms SET deleted_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
                 WHERE id = ? AND deleted_at IS NULL',
                [$farmId]
            );
            (new Audit_logger($this->db))->record(
                (int) $user['id'],
                $user['role_slug'],
                (int) $farm['barangay_id'],
                'archive',
                'farms',
                $farmId
            );
            $this->db->commit();
            return true;
        } catch (Throwable $exception) {
            $this->db->roll_back();
            throw $exception;
        }
    }

    private function find_farmer_in_scope(array $user, $farmerId)
    {
        [$scope, $params] = $this->scope($user, 'farmers');
        return $this->db->raw(
            'SELECT farmers.id, farmers.barangay_id
             FROM farmers
             INNER JOIN barangays ON barangays.id = farmers.barangay_id
             WHERE farmers.id = ? AND farmers.is_active = 1
               AND farmers.deleted_at IS NULL AND barangays.is_active = 1
               AND barangays.deleted_at IS NULL'
             . ($scope ? ' AND ' . implode(' AND ', $scope) : '') . ' LIMIT 1',
            array_merge([$farmerId], $params)
        )->fetch(PDO::FETCH_ASSOC);
    }

    private function scope(array $user, $alias)
    {
        if ($user['role_slug'] === 'barangay_personnel') {
            return [[$alias . '.barangay_id = ?'], [(int) $user['barangay_id']]];
        }
        if ($user['role_slug'] === 'farmer') {
            return [[$alias . '.farmer_id = ?'], [(int) $user['farmer_id']]];
        }
        return [[], []];
    }
}
