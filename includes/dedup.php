<?php
/**
 * Dedup helper — find possibly-matching un-archived persons before
 * inserting a new client/patient (or any new person).
 *
 * Match signals (any one fires the prompt):
 *   - LOWER(full_name) similar (Levenshtein <= 3 OR same metaphone-ish key)
 *   - Any phone exactly matches an existing person_phones row
 *   - Any email exactly matches an existing person_emails row (case-insensitive)
 *   - id_passport exact match
 *
 * Scoped to persons whose person_type SET overlaps $personType when given,
 * otherwise scans all un-archived persons.
 *
 * Returns at most $limit candidates, ordered by match strength.
 */

if (!function_exists('getDB')) {
    require_once __DIR__ . '/db.php';
}

/**
 * @param string|null $personType   'client', 'patient', 'caregiver', or null for any
 * @param array{
 *   full_name?: string,
 *   phones?: array<int,string>,
 *   emails?: array<int,string>,
 *   id_passport?: string
 * } $candidate
 * @param int $limit
 * @return array<int,array{
 *   person_id:int, full_name:string, tch_id:?string, person_type:string,
 *   reasons:array<int,string>, score:int
 * }>
 */
function findPossibleDuplicates(?string $personType, array $candidate, int $limit = 10): array
{
    $db = getDB();
    $matches = []; // person_id => ['row' => row, 'reasons' => [...], 'score' => N]

    // --- 1. Exact phone match ---------------------------------
    if (!empty($candidate['phones'])) {
        $phoneList = array_filter(array_map('trim', $candidate['phones']));
        if (!empty($phoneList)) {
            $placeholders = implode(',', array_fill(0, count($phoneList), '?'));
            $sql = "SELECT pp.person_id, pp.phone
                    FROM person_phones pp
                    JOIN persons p ON p.id = pp.person_id
                    WHERE p.archived_at IS NULL
                      AND pp.phone IN ($placeholders)";
            $stmt = $db->prepare($sql);
            $stmt->execute(array_values($phoneList));
            foreach ($stmt->fetchAll() as $row) {
                $pid = (int)$row['person_id'];
                if (!isset($matches[$pid])) $matches[$pid] = ['reasons' => [], 'score' => 0];
                $matches[$pid]['reasons'][] = 'Same phone (' . $row['phone'] . ')';
                $matches[$pid]['score'] += 100;
            }
        }
    }

    // --- 2. Exact email match ---------------------------------
    if (!empty($candidate['emails'])) {
        $emailList = array_values(array_filter(array_map(
            fn($e) => strtolower(trim((string)$e)),
            $candidate['emails']
        )));
        if (!empty($emailList)) {
            $placeholders = implode(',', array_fill(0, count($emailList), '?'));
            $sql = "SELECT pe.person_id, pe.email
                    FROM person_emails pe
                    JOIN persons p ON p.id = pe.person_id
                    WHERE p.archived_at IS NULL
                      AND LOWER(pe.email) IN ($placeholders)";
            $stmt = $db->prepare($sql);
            $stmt->execute($emailList);
            foreach ($stmt->fetchAll() as $row) {
                $pid = (int)$row['person_id'];
                if (!isset($matches[$pid])) $matches[$pid] = ['reasons' => [], 'score' => 0];
                $matches[$pid]['reasons'][] = 'Same email (' . $row['email'] . ')';
                $matches[$pid]['score'] += 100;
            }
        }
    }

    // --- 3. ID / Passport exact match -------------------------
    if (!empty($candidate['id_passport'])) {
        $idp = trim((string)$candidate['id_passport']);
        if ($idp !== '') {
            $sql = "SELECT id FROM persons
                    WHERE archived_at IS NULL AND id_passport = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$idp]);
            foreach ($stmt->fetchAll() as $row) {
                $pid = (int)$row['id'];
                if (!isset($matches[$pid])) $matches[$pid] = ['reasons' => [], 'score' => 0];
                $matches[$pid]['reasons'][] = 'Same ID/Passport';
                $matches[$pid]['score'] += 100;
            }
        }
    }

    // --- 4. Name similarity (Levenshtein + soundex) -----------
    if (!empty($candidate['full_name'])) {
        $name = strtolower(trim((string)$candidate['full_name']));
        if ($name !== '') {
            $sql = "SELECT id, full_name, person_type, tch_id
                    FROM persons
                    WHERE archived_at IS NULL
                      AND full_name IS NOT NULL
                      AND full_name <> ''";
            // Narrow to same person_type if provided. SET column → use FIND_IN_SET.
            $params = [];
            if ($personType !== null && $personType !== '') {
                $sql .= " AND FIND_IN_SET(?, person_type)";
                $params[] = $personType;
            }
            // Cap to 5,000 candidates so a runaway scan can't lock things up.
            $sql .= " LIMIT 5000";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $candidateSoundex = soundex($name);
            foreach ($stmt->fetchAll() as $row) {
                $existing = strtolower(trim((string)$row['full_name']));
                if ($existing === '') continue;
                $lev = levenshtein($name, $existing);
                $sndex = soundex($existing);
                $hit = false;
                $reason = '';
                if ($lev <= 3) {
                    $hit = true;
                    $reason = 'Similar name (Levenshtein ' . $lev . ')';
                } elseif ($sndex === $candidateSoundex && $sndex !== '') {
                    $hit = true;
                    $reason = 'Similar-sounding name';
                }
                if ($hit) {
                    $pid = (int)$row['id'];
                    if (!isset($matches[$pid])) $matches[$pid] = ['reasons' => [], 'score' => 0];
                    $matches[$pid]['reasons'][] = $reason;
                    // Lower scores than exact-field matches so true exact wins.
                    $matches[$pid]['score'] += max(50 - ($lev * 5), 10);
                }
            }
        }
    }

    if (empty($matches)) return [];

    // Hydrate the rows we matched
    $ids = array_keys($matches);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT id AS person_id, full_name, tch_id, person_type, archived_at
            FROM persons
            WHERE id IN ($placeholders) AND archived_at IS NULL";
    $stmt = $db->prepare($sql);
    $stmt->execute($ids);
    $hydrated = [];
    foreach ($stmt->fetchAll() as $row) {
        $pid = (int)$row['person_id'];
        $row['reasons'] = $matches[$pid]['reasons'];
        $row['score']   = $matches[$pid]['score'];
        $hydrated[] = $row;
    }
    usort($hydrated, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($hydrated, 0, $limit);
}
