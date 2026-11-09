document.querySelectorAll('[data-forum-draft]').forEach((container) => {
    const form = container.closest('form');
    const body = form?.querySelector('[name="body"]');
    if (!form || !body) {
        return;
    }
    const title = form.querySelector('[name="title"]');
    const follow = form.querySelector('input[type="checkbox"][name="follow"]');
    const feedback = container.querySelector('[data-draft-feedback]');
    const discard = container.querySelector('[data-draft-discard]');
    const discardButton = container.querySelector('[data-draft-discard-button]');
    const editor = () => window.tinymce?.get(body.id);
    const read = () =>
        JSON.stringify({
            context: container.dataset.draftContext,
            title: title?.value ?? null,
            body: editor()?.getContent() ?? body.value,
            tags: [...form.querySelectorAll('[name="tags[]"]:checked')].map((field) => Number(field.value)),
            follow: Boolean(follow?.checked),
        });
    let baseline = read();
    let pending = null;
    let paused = false;
    let stopped = false;
    let nextAttempt = 0;
    const message = (text, error = false) => {
        feedback.textContent = text;
        feedback.classList.toggle('text-danger', error);
        feedback.classList.toggle('text-body-secondary', !error);
    };
    const request = async (address, method, payload) => {
        const url = new URL(address, window.location.href);
        if (url.origin !== window.location.origin) {
            throw new Error('Invalid draft endpoint');
        }
        const abort = new AbortController();
        const timeout = window.setTimeout(() => abort.abort(), 20000);
        let response;
        try {
            response = await fetch(url, {
                method,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': form.querySelector('[name="_token"]')?.value ?? '',
                },
                body: payload,
                signal: abort.signal,
            });
        } finally {
            window.clearTimeout(timeout);
        }
        const envelope = await response.json().catch(() => null);
        if (!response.ok || envelope?.success !== true) {
            if ([401, 403, 419].includes(response.status)) {
                stopped = true;
            }
            const retry = Number(response.headers.get('Retry-After'));
            nextAttempt = Date.now() + (Number.isFinite(retry) && retry > 0 ? Math.min(retry, 3600) * 1000 : 15000);
            const error = new Error('Draft request failed');
            error.limit = envelope?.data?.errors?.body?.includes(container.dataset.limit);
            throw error;
        }
        return response;
    };
    const save = () => {
        if (pending || paused || stopped || nextAttempt > Date.now() || !form.isConnected) {
            return;
        }
        const payload = read();
        if (payload === baseline) {
            return;
        }
        pending = request(container.dataset.draftUrl, 'POST', payload)
            .then(() => {
                baseline = payload;
                discard.hidden = false;
                message(container.dataset.saved);
            })
            .catch((error) => {
                nextAttempt = Math.max(nextAttempt, Date.now() + 15000);
                message(error.limit ? container.dataset.limit : container.dataset.failed, true);
            })
            .finally(() => {
                pending = null;
            });
    };
    const timer = window.setInterval(save, 5000);
    discardButton.addEventListener('click', async () => {
        if (paused || stopped) {
            return;
        }
        paused = true;
        discardButton.disabled = true;
        try {
            if (pending) {
                await pending;
            }
            await request(
                container.dataset.discardUrl,
                'DELETE',
                JSON.stringify({ context: container.dataset.draftContext }),
            );
            if (title) {
                title.value = '';
            }
            body.value = '';
            editor()?.setContent('');
            form.querySelectorAll('[name="tags[]"]').forEach((field) => {
                field.checked = false;
            });
            baseline = read();
            discard.open = false;
            discard.hidden = true;
            message(container.dataset.discarded);
        } catch {
            message(container.dataset.failed, true);
        } finally {
            paused = false;
            discardButton.disabled = false;
        }
    });
    form.addEventListener('submit', (event) => {
        if (event.defaultPrevented) {
            return;
        }
        window.tinymce?.triggerSave();
        if (!form.checkValidity()) {
            return;
        }
        paused = true;
        const resume = () => {
            if (event.defaultPrevented && !pending) {
                paused = form.getAttribute('aria-busy') === 'true';
            } else {
                stopped = true;
                window.clearInterval(timer);
            }
        };
        if (pending) {
            event.preventDefault();
            const submitter = event.submitter;
            pending.then(() => {
                paused = false;
                if (submitter) {
                    form.requestSubmit(submitter);
                } else {
                    form.requestSubmit();
                }
            });
        } else {
            queueMicrotask(resume);
        }
    });
    form.addEventListener('ajax:success', () => {
        stopped = true;
        window.clearInterval(timer);
    });
    form.addEventListener('ajax:error', () => {
        paused = false;
    });
    window.addEventListener('beforeunload', (event) => {
        if (!stopped && (pending || read() !== baseline)) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
});
