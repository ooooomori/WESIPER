// Reuse successful responses and ongoing requests until the page is reloaded.
const responses = new Map();
const requests = new Map();
const cacheKey = url => {
    const parsed = new URL(url, 'http://profile.local');
    parsed.searchParams.sort();
    return `${parsed.pathname}${parsed.search}`;
};

export function getCachedProfileData(url) {
    return responses.get(cacheKey(url));
}

export function loadProfileData(url, validate = () => true) {
    const key = cacheKey(url);
    if (!requests.has(key)) {
        const request = fetch(url)
            .then(async response => {
                const data = await response.json();
                if (!response.ok || !validate(data)) throw new Error(data.error || '기록을 불러오지 못했습니다.');
                responses.set(key, data);
                return data;
            })
            .catch(error => { requests.delete(key); throw error; });
        requests.set(key, request);
    }
    return requests.get(key);
}
