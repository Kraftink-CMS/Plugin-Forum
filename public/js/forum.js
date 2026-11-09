const initialized = new WeakSet();

const initialize = (root = document) => {
    root.querySelectorAll('[data-forum-editor]').forEach((field) => {
        if (initialized.has(field)) {
            return;
        }
        initialized.add(field);
        const category = field.closest('form')?.querySelector('[data-forum-category]');
        if (!category) {
            return;
        }
        const configure = () => {
            const id = /^[0-9]{1,18}$/.test(category.value) ? category.value : '0';
            field.dataset.editorUploadUrl = field.dataset.forumUploadTemplate.replace('/0/', '/' + id + '/');
        };
        category.addEventListener('change', configure);
        configure();
    });
};

initialize();
document.addEventListener('kraft:content-added', (event) => initialize(event.target));
