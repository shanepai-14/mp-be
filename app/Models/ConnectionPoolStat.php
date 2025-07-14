<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Class ConnectionPoolStat
 * 
 * MySQL model for tracking GPS connection pool statistics
 * 
 * @property int $id
 * @property string $pool_key
 * @property string $process_id  
 * @property int $created
 * @property int $success
 * @property int $reused
 * @property int $send_failed
 * @property int $connection_failed
 * @property string $last_action
 * @property \Carbon\Carbon $last_action_time
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class ConnectionPoolStat extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'connection_pool_stats';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'pool_key',
        'process_id',
        'created',
        'success',
        'reused', 
        'send_failed',
        'connection_failed',
        'last_action',
        'last_action_time'
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'created' => 'integer',
        'success' => 'integer', 
        'reused' => 'integer',
        'send_failed' => 'integer',
        'connection_failed' => 'integer',
        'last_action_time' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Get stats for a specific pool and process
     */
    public static function getPoolStats(string $poolKey, string $processId): ?self
    {
        return self::where('pool_key', $poolKey)
                  ->where('process_id', $processId)
                  ->first();
    }

    /**
     * Increment a specific stat counter
     */
    public function incrementStat(string $statName): void
    {
        $this->increment($statName);
        $this->update([
            'last_action' => $statName,
            'last_action_time' => now()
        ]);
    }

    /**
     * Get global stats across all processes for a pool
     */
    public static function getGlobalStats(string $poolKey): array
    {
        $stats = self::where('pool_key', $poolKey)
                    ->selectRaw('
                        SUM(created) as total_created,
                        SUM(success) as total_success, 
                        SUM(reused) as total_reused,
                        SUM(send_failed) as total_send_failed,
                        SUM(connection_failed) as total_connection_failed,
                        COUNT(*) as total_processes
                    ')
                    ->first();

        if (!$stats) {
            return [
                'total_created' => 0,
                'total_success' => 0,
                'total_reused' => 0,
                'total_send_failed' => 0,
                'total_connection_failed' => 0,
                'total_processes' => 0,
                'reuse_ratio' => 0
            ];
        }

        $totalCreated = $stats->total_created ?? 0;
        $totalReused = $stats->total_reused ?? 0;

        return [
            'total_created' => $totalCreated,
            'total_success' => $stats->total_success ?? 0,
            'total_reused' => $totalReused, 
            'total_send_failed' => $stats->total_send_failed ?? 0,
            'total_connection_failed' => $stats->total_connection_failed ?? 0,
            'total_processes' => $stats->total_processes ?? 0,
            'reuse_ratio' => $totalCreated > 0 ? round($totalReused / $totalCreated, 2) : 0
        ];
    }

    /**
     * Get all pool stats summary
     */
    public static function getAllPoolsStats(): array
    {
        return self::groupBy('pool_key')
                  ->selectRaw('
                      pool_key,
                      SUM(created) as total_created,
                      SUM(success) as total_success,
                      SUM(reused) as total_reused, 
                      SUM(send_failed) as total_send_failed,
                      SUM(connection_failed) as total_connection_failed,
                      COUNT(*) as total_processes
                  ')
                  ->get()
                  ->map(function ($stat) {
                      $totalCreated = $stat->total_created ?? 0;
                      $totalReused = $stat->total_reused ?? 0;
                      
                      return [
                          'pool_key' => $stat->pool_key,
                          'total_created' => $totalCreated,
                          'total_success' => $stat->total_success ?? 0,
                          'total_reused' => $totalReused,
                          'total_send_failed' => $stat->total_send_failed ?? 0,
                          'total_connection_failed' => $stat->total_connection_failed ?? 0,
                          'total_processes' => $stat->total_processes ?? 0,
                          'reuse_ratio' => $totalCreated > 0 ? round($totalReused / $totalCreated, 2) : 0
                      ];
                  })
                  ->toArray();
    }

    /**
     * Clean up old stats (older than specified days)
     */
    public static function cleanupOldStats(int $days = 7): int
    {
        $cutoffDate = now()->subDays($days);
        
        return self::where('updated_at', '<', $cutoffDate)->delete();
    }

    /**
     * Get reuse efficiency for this pool/process
     */
    public function getReuseRatio(): float
    {
        if ($this->created <= 0) {
            return 0;
        }
        
        return round($this->reused / $this->created, 2);
    }

    /**
     * Get success rate for this pool/process
     */
    public function getSuccessRate(): float
    {
        $totalAttempts = $this->success + $this->send_failed;
        
        if ($totalAttempts <= 0) {
            return 0;
        }
        
        return round(($this->success / $totalAttempts) * 100, 2);
    }

    /**
     * Check if this process is active (updated recently)
     */
    public function isActiveProcess(): bool
    {
        return $this->updated_at && $this->updated_at->diffInMinutes(now()) <= 5;
    }

    /**
     * Scope for active processes
     */
    public function scopeActive($query)
    {
        return $query->where('updated_at', '>=', now()->subMinutes(5));
    }

    /**
     * Scope for specific pool
     */
    public function scopeForPool($query, string $poolKey)
    {
        return $query->where('pool_key', $poolKey);
    }
}