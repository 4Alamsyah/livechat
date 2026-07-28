function xsrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export async function agentFetch(url: string, options: RequestInit = {}) {
    const headers: Record<string, string> = { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() };
    if (options.body) {
        headers['Content-Type'] = 'application/json';
    }
    const res = await fetch(url, { ...options, headers, credentials: 'same-origin' });
    if (!res.ok) {
        throw new Error('Request failed (' + res.status + ')');
    }
    if (res.status === 204) {
        return null;
    }
    return res.json();
}

export async function agentUpload(url: string, formData: FormData) {
    const res = await fetch(url, {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
        body: formData,
        credentials: 'same-origin',
    });
    if (!res.ok) {
        throw new Error('Request failed (' + res.status + ')');
    }
    return res.json();
}
