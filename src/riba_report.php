<?php
declare(strict_types=1);

namespace App;

use PDO;

final class RibaReport
{
    /**
     * Resmi Excel şablonlarından türetilmiş eşleştirme/puanlama kuralları.
     * Dosya: config/riba_scoring.php
     */
    public static function scoring(): array
    {
        static $cfg = null;
        if (is_array($cfg)) {
            return $cfg;
        }
        $root = dirname(__DIR__);
        $path = $root . '/config/riba_scoring.php';
        if (!is_file($path)) {
            Http::text(500, "Puanlama dosyası bulunamadı: config/riba_scoring.php\n");
            exit;
        }
        /** @var array $loaded */
        $loaded = require $path;
        $cfg = $loaded;
        return $cfg;
    }

    /**
     * A/B dağılımı: form_instance_id için item_no bazında sayım.
     *
     * @return array{counts: array<int, array{A:int,B:int,total:int}>, totals: array{A:int,B:int,total:int}}
     */
    public static function distributionForForm(PDO $pdo, int $formInstanceId): array
    {
        $stmt = $pdo->prepare('
            SELECT ra.item_no, ra.choice, COUNT(*) AS cnt
            FROM response_answers ra
            JOIN responses r ON r.id = ra.response_id
            WHERE r.form_instance_id = :fid
            GROUP BY ra.item_no, ra.choice
        ');
        $stmt->execute([':fid' => $formInstanceId]);

        $counts = [];
        $totA = 0;
        $totB = 0;
        foreach ($stmt->fetchAll() as $row) {
            $itemNo = (int)$row['item_no'];
            $choice = (string)$row['choice'];
            $cnt = (int)$row['cnt'];
            if (!isset($counts[$itemNo])) {
                $counts[$itemNo] = ['A' => 0, 'B' => 0, 'total' => 0];
            }
            if ($choice === 'A') {
                $counts[$itemNo]['A'] += $cnt;
                $totA += $cnt;
            } elseif ($choice === 'B') {
                $counts[$itemNo]['B'] += $cnt;
                $totB += $cnt;
            }
            $counts[$itemNo]['total'] += $cnt;
        }

        return [
            'counts' => $counts,
            'totals' => ['A' => $totA, 'B' => $totB, 'total' => $totA + $totB],
        ];
    }

    /**
     * Excel şablonlarındaki mantığa göre:
     * - f: RS için (belirlenen item_no + A/B seçimi) sayımı
     * - Z: z = ((f - AVG(f)) / STDEV(f)) * 10 + 50
     * - ASP: z'lerin ağırlıklı toplamı
     *
     * @return array{asp: array<int,float|null>, targets: array<int,string>}
     */
    public static function classAsp(PDO $pdo, string $schoolType, int $schoolId, int $campaignId, int $classId): array
    {
        $scoring = self::scoring();
        if (!isset($scoring[$schoolType])) {
            return ['asp' => [], 'targets' => []];
        }
        $cfg = $scoring[$schoolType];

        /** @var int[] $rsList */
        $rsList = $cfg['rs_list'] ?? [];
        /** @var array<int,string> $targets */
        $targets = $cfg['targets'] ?? [];
        /** @var array<string,float> $weights */
        $weights = $cfg['weights'] ?? [];
        /** @var array<string,array<int,array{item_no:int,choice:string}|null>> $mappings */
        $mappings = $cfg['mappings'] ?? [];

        // Form instance id'lerini bul (dönem + sınıf + hedef kitle)
        $forms = self::classFormInstances($pdo, $schoolId, $campaignId, $classId);

        // Her hedef kitle için f ve z hesapla
        $zScoresByAudience = [];
        foreach ($weights as $audience => $w) {
            if (!isset($forms[$audience])) {
                // Bu okul türünde bu hedef kitle yok olabilir (örn: okul öncesi öğrenci yok)
                continue;
            }
            $formId = $forms[$audience];
            $dist = self::distributionForForm($pdo, $formId);
            $counts = $dist['counts'];

            // f değerleri
            $fByRs = [];
            foreach ($rsList as $rs) {
                $map = $mappings[$audience][$rs] ?? null;
                if ($map === null) {
                    $fByRs[$rs] = 0.0;
                    continue;
                }
                $itemNo = (int)$map['item_no'];
                $choice = (string)$map['choice'];
                $cnt = 0;
                if (isset($counts[$itemNo])) {
                    $cnt = ($choice === 'A') ? (int)$counts[$itemNo]['A'] : (int)$counts[$itemNo]['B'];
                }
                $fByRs[$rs] = (float)$cnt;
            }

            $mean = self::mean(array_values($fByRs));
            $stdev = self::stdevSample(array_values($fByRs));
            $zByRs = [];
            foreach ($rsList as $rs) {
                if ($stdev <= 0.0) {
                    $zByRs[$rs] = null;
                    continue;
                }
                $f = $fByRs[$rs];
                $zByRs[$rs] = (($f - $mean) / $stdev) * 10.0 + 50.0;
            }
            $zScoresByAudience[$audience] = $zByRs;
        }

        // ASP = ağırlıklı z toplamı
        $asp = [];
        foreach ($rsList as $rs) {
            $sum = 0.0;
            $hasNull = false;
            foreach ($weights as $audience => $w) {
                if (!isset($zScoresByAudience[$audience])) {
                    // bu hedef kitle yoksa katkı yok
                    continue;
                }
                $z = $zScoresByAudience[$audience][$rs] ?? null;
                if ($z === null) {
                    $hasNull = true;
                    break;
                }
                $sum += $z * (float)$w;
            }
            $asp[$rs] = $hasNull ? null : $sum;
        }

        return ['asp' => $asp, 'targets' => $targets];
    }

    /**
     * Okul ASP: Excel okul sonuç çizelgesindeki gibi sınıfların ortalaması.
     *
     * @param array<int,array<int,float|null>> $classAspByClassId class_id => (rs => asp)
     * @return array<int,float|null> rs => avg
     */
    public static function schoolAspAverage(array $classAspByClassId, array $rsList): array
    {
        $out = [];
        foreach ($rsList as $rs) {
            $vals = [];
            foreach ($classAspByClassId as $classId => $aspByRs) {
                $v = $aspByRs[$rs] ?? null;
                if ($v === null) {
                    continue;
                }
                $vals[] = (float)$v;
            }
            $out[$rs] = count($vals) > 0 ? self::mean($vals) : null;
        }
        return $out;
    }

    /**
     * @return array<string,int> audience => form_instance_id
     */
    public static function classFormInstances(PDO $pdo, int $schoolId, int $campaignId, int $classId): array
    {
        $stmt = $pdo->prepare('
            SELECT audience, id
            FROM form_instances
            WHERE school_id = :sid AND campaign_id = :camp AND class_id = :cid
        ');
        $stmt->execute([':sid' => $schoolId, ':camp' => $campaignId, ':cid' => $classId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string)$row['audience']] = (int)$row['id'];
        }
        return $out;
    }

    public static function mean(array $values): float
    {
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($values as $v) {
            $sum += (float)$v;
        }
        return $sum / (float)$n;
    }

    /**
     * Excel'deki STDEV (örneklem) gibi: n-1.
     */
    public static function stdevSample(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }
        $mean = self::mean($values);
        $sumSq = 0.0;
        foreach ($values as $v) {
            $d = (float)$v - $mean;
            $sumSq += $d * $d;
        }
        return sqrt($sumSq / (float)($n - 1));
    }
}

