// Shared across profile mounts; a page reload starts a new cache.
const requests = new Map();
const records = new Map();

export function getCachedYearRecords(pid) {
    return records.get(String(pid)) || null;
}

export function loadYearRecords(pid) {
    const key = String(pid);
    if (!requests.has(key)) {
        const request = fetch(`/api/playerProfile.php?pid=${encodeURIComponent(key)}&part=year-records&type=all`)
            .then(async response => {
                const result = await response.json();
                if (!response.ok || !result.batter || !result.pitcher) throw new Error(result.error || '기록을 불러오지 못했습니다.');
                records.set(key, result);
                return result;
            })
            .catch(error => {
                requests.delete(key);
                throw error;
            });
        requests.set(key, request);
    }
    return requests.get(key);
}
