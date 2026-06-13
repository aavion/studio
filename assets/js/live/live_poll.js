export class LivePoller {
    constructor({
        interval = 750,
        onPayload = () => {},
        onError = () => {},
        onDone = () => {},
        fetcher = window.fetch.bind(window),
    } = {}) {
        this.interval = Number(interval || 0);
        this.onPayload = onPayload;
        this.onError = onError;
        this.onDone = onDone;
        this.fetcher = fetcher;
        this.active = false;
    }

    async poll(url, cursor = 0) {
        this.active = true;
        let nextCursor = Number(cursor || 0);

        try {
            while (this.active) {
                const response = await this.fetcher(this.urlWithCursor(url, nextCursor), {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) {
                    this.onError(response, null);

                    return null;
                }

                const payload = await this.readJson(response);
                nextCursor = Number(payload.cursor || nextCursor);
                this.onPayload(payload, nextCursor);

                if (this.isTerminal(payload) || 0 === this.interval) {
                    this.active = false;
                    this.onDone(payload);

                    return payload;
                }

                await this.sleep(Number(payload.next_poll_ms || this.interval));
            }
        } catch (error) {
            this.active = false;
            this.onError(null, error);
        }

        return null;
    }

    stop() {
        this.active = false;
    }

    urlWithCursor(url, cursor) {
        const liveUrl = new URL(url, window.location.origin);
        liveUrl.searchParams.set('cursor', String(Math.max(0, Number(cursor || 0))));

        return liveUrl.toString();
    }

    async readJson(response) {
        const contentType = response.headers.get('content-type') || '';

        if (!contentType.includes('application/json')) {
            throw new Error('Expected a JSON response from the live endpoint.');
        }

        return response.json();
    }

    isTerminal(payload) {
        return ['success', 'requires_review', 'failed'].includes(payload?.status);
    }

    sleep(ms) {
        return new Promise((resolve) => window.setTimeout(resolve, ms));
    }
}

export function liveRouteUrl(relativeRoute) {
    const route = String(relativeRoute || '').replace(/^\/+/, '');

    return new URL(`api/live/${route}`, window.location.origin).toString();
}
