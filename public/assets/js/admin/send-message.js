(function () {
    const $msg = document.getElementById('message');
    if (!$msg) { return; }

    function wrapSelection(open, close = open) {
        const start = $msg.selectionStart, end = $msg.selectionEnd;
        const before = $msg.value.slice(0, start);
        const sel    = $msg.value.slice(start, end);
        const after  = $msg.value.slice(end);
        const insert = open + (sel || 'текст') + close;
        $msg.value = before + insert + after;
        $msg.focus();
        const cursor = before.length + open.length + (sel ? sel.length : 'текст'.length);
        $msg.setSelectionRange(cursor, cursor);
    }
    function prefixLines(prefix) {
        const start = $msg.selectionStart, end = $msg.selectionEnd;
        const before = $msg.value.slice(0, start);
        const sel    = $msg.value.slice(start, end) || 'текст';
        const after  = $msg.value.slice(end);
        const lines  = sel.split('\n').map(l => prefix + l).join('\n');
        $msg.value = before + lines + after;
        $msg.focus();
    }
    function insertAt(text) {
        const start = $msg.selectionStart, end = $msg.selectionEnd;
        $msg.value = $msg.value.slice(0, start) + text + $msg.value.slice(end);
        $msg.focus();
        const cursor = start + text.length;
        $msg.setSelectionRange(cursor, cursor);
    }
    function insertLink() {
        const url = prompt('URL ссылки', 'https://');
        if (!url) { return; }
        const start = $msg.selectionStart, end = $msg.selectionEnd;
        const sel = $msg.value.slice(start, end) || 'текст ссылки';
        const before = $msg.value.slice(0, start);
        const after  = $msg.value.slice(end);
        $msg.value = before + '[' + sel + '](' + url + ')' + after;
        $msg.focus();
    }

    document.querySelectorAll('.msg-toolbar [data-wrap]').forEach(btn => {
        btn.addEventListener('click', () => wrapSelection(btn.getAttribute('data-wrap')));
    });
    document.querySelectorAll('.msg-toolbar [data-prefix]').forEach(btn => {
        btn.addEventListener('click', () => prefixLines(btn.getAttribute('data-prefix')));
    });
    document.querySelectorAll('.msg-toolbar [data-emoji]').forEach(btn => {
        btn.addEventListener('click', () => insertAt(btn.getAttribute('data-emoji')));
    });
    const linkBtn = document.querySelector('.msg-toolbar [data-action="link"]');
    if (linkBtn) { linkBtn.addEventListener('click', insertLink); }

    $msg.addEventListener('keydown', (e) => {
        if (!(e.ctrlKey || e.metaKey)) { return; }
        const k = e.key.toLowerCase();
        if (k === 'b') { e.preventDefault(); wrapSelection('*'); }
        else if (k === 'i') { e.preventDefault(); wrapSelection('_'); }
        else if (k === 'e') { e.preventDefault(); wrapSelection('`'); }
    });

    const blocks = {
        none:        null,
        upload:      document.getElementById('image_upload_block'),
        ai_generate: document.getElementById('image_ai_block'),
    };
    document.querySelectorAll('input[name="image_mode"]').forEach(radio => {
        radio.addEventListener('change', () => {
            Object.values(blocks).forEach(b => { if (b) { b.style.display = 'none'; } });
            const block = blocks[radio.value];
            if (block) { block.style.display = 'block'; }
        });
    });
})();
