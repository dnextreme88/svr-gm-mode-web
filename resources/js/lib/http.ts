/**
 * Minimal JSON requests for endpoints that return data rather than an
 * Inertia page (the Randomize / Preset proposals, §24).
 */
export class HttpError extends Error {
    constructor(
        public status: number,
        message: string,
    ) {
        super(message);
    }
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export async function requestJson<T>(
    method: 'POST' | 'PUT' | 'DELETE',
    url: string,
    body: unknown = {},
): Promise<T> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        const data = (await response.json().catch(() => ({}))) as {
            message?: string;
        };

        throw new HttpError(
            response.status,
            data.message ?? 'Something went wrong.',
        );
    }

    // Booking endpoints answer with a redirect to the card page, not JSON.
    if (!response.headers.get('content-type')?.includes('application/json')) {
        return {} as T;
    }

    return (await response.json()) as T;
}
