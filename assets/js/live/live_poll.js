export class LivePoller {
    constructor({
        interval = 750,
        onPayload = () => {},
        onError = () => {},
        onDone = () => {},
        fetcher = window.fetch.bind(window),
        invalidJsonMessage = 'The live endpoint returned an invalid response.',
    } = {}) {
        this.interval = Number(interval || 0);
        this.onPayload = onPayload;
        this.onError = onError;
        this.onDone = onDone;
        this.fetcher = fetcher;
        this.invalidJsonMessage = invalidJsonMessage;
        this.active = false;
    }

    async poll(url, cursor = 0) {
        this.active = true;
        let nextCursor = Number(cursor || 0);

        try {
            while (this.active) {
                const result = await this.fetchPayload(url, nextCursor);

                if (!result) {
                    return null;
                }

                const { payload } = result;
                nextCursor = result.cursor;
                this.onPayload(payload, nextCursor);

                const nextDelay = Number(payload.next_poll_ms ?? this.interval);

                if (this.isTerminal(payload) || nextDelay <= 0) {
                    this.active = false;
                    this.onDone(payload);

                    return payload;
                }

                await this.sleep(nextDelay);
            }
        } catch (error) {
            this.active = false;
            this.onError(null, error);
        }

        return null;
    }

    async pollOnce(url, cursor = 0) {
        this.active = true;

        try {
            const result = await this.fetchPayload(url, Number(cursor || 0));

            if (!result) {
                return null;
            }

            this.onPayload(result.payload, result.cursor);
            this.onDone(result.payload);

            return result.payload;
        } catch (error) {
            this.onError(null, error);

            return null;
        } finally {
            this.active = false;
        }
    }

    stop() {
        this.active = false;
    }

    async fetchPayload(url, cursor) {
        const response = await this.fetcher(this.urlWithCursor(url, cursor), {
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

        return {
            cursor: Number(payload.cursor || cursor),
            payload,
        };
    }

    urlWithCursor(url, cursor) {
        const liveUrl = new URL(url, window.location.origin);
        liveUrl.searchParams.set('cursor', String(Math.max(0, Number(cursor || 0))));

        return liveUrl.toString();
    }

    async readJson(response) {
        const contentType = response.headers.get('content-type') || '';

        if (!contentType.includes('application/json')) {
            throw new Error(this.invalidJsonMessage);
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
