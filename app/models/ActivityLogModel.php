<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ActivityLogModel extends Model
{
    public function record(string $action, ?int $actorId): void
    {
        $actor = $actorId ? $this->db->table('users')
            ->select('username,role')
            ->where('id', '=', $actorId)
            ->get(PDO::FETCH_ASSOC) : null;
        $this->db->table('activity_logs')
            ->insert([
                'actor_id' => $actorId,
                'actor_name' => $actor['username'] ?? 'Student activation',
                'actor_role' => $actor['role'] ?? 'STUDENT',
                'action' => $action,
                'created_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s')
            ]);
    }
}
