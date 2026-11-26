// A Preview button on each theme block (BlockPreview.php inlines this with its settings in
// window.gafferBlockPreview): a modal with the post's preview URL in an iframe, the block's
// unsaved markup (or the whole unsaved post) posted to it. Only WordPress's public editor API.
(function (wp, settings) {
    if (!wp || !settings) {
        return;
    }
    var el = wp.element.createElement;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;
    var useRef = wp.element.useRef;
    var label = settings.labels;
    var components = wp.components;
    var blocks = settings.blocks;
    var widths = { desktop: '100%', tablet: '820px', mobile: '390px' };

    function post(frame, content, title) {
        var form = document.createElement('form');
        form.method = 'post';
        form.action = settings.url;
        form.target = frame.name;
        form.style.display = 'none';
        [[settings.field, content], [settings.title_field, title], [settings.nonce_field, settings.nonce]].forEach(function (pair) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = pair[0];
            input.value = pair[1];
            form.appendChild(input);
        });
        document.body.appendChild(form);
        form.submit();
        setTimeout(function () { form.remove(); }, 1000); // removed at once, a browser may drop the submit
    }

    function Preview(props) {
        var frame = useRef(null);
        var scope = useState('block');
        var width = useState('desktop');
        var name = 'gaffer-block-preview-' + props.clientId + '-' + scope[0]; // a fresh frame per scope

        useEffect(function () {
            var content = scope[0] === 'block'
                ? wp.blocks.serialize([wp.data.select('core/block-editor').getBlock(props.clientId)])
                : wp.data.select('core/editor').getEditedPostContent();
            if (frame.current) {
                post(frame.current, content, wp.data.select('core/editor').getEditedPostAttribute('title') || '');
            }
        }, [scope[0]]);

        function toggle(state, options) {
            return el('div', { style: { display: 'flex' } }, options.map(function (option) {
                return el(components.Button, {
                    key: option[0],
                    variant: state[0] === option[0] ? 'primary' : 'secondary',
                    onClick: function () { state[1](option[0]); },
                }, option[1]);
            }));
        }

        // Full screen: the modal's content is a flex box whose last child fills it, so only the
        // page in the frame scrolls, never the modal around it.
        return el(components.Modal, {
            title: label.preview + ': ' + props.title,
            isFullScreen: true,
            onRequestClose: props.onClose,
        }, el('div', { style: { display: 'flex', flexDirection: 'column', gap: '12px', height: '100%' } },
            el('div', { style: { display: 'flex', gap: '16px', flexWrap: 'wrap' } },
                toggle(scope, [['block', label.block], ['page', label.page]]),
                toggle(width, [['desktop', label.desktop], ['tablet', label.tablet], ['mobile', label.mobile]])),
            el('div', { style: { background: '#f0f0f0', flex: 1, minHeight: 0, display: 'flex', justifyContent: 'center' } },
                el('iframe', {
                    key: name,
                    ref: frame,
                    name: name,
                    title: label.preview,
                    style: { width: widths[width[0]], maxWidth: '100%', height: '100%', border: 0, background: '#fff' },
                }))));
    }

    // The nonce outlives no editor left open for a day: WordPress's heartbeat brings a fresh one
    // (BlockPreview::refresh()), as it does for its own.
    wp.hooks.addAction('heartbeat.send', 'gaffer/block-preview', function (data) {
        data[settings.heartbeat] = settings.post;
    });
    wp.hooks.addAction('heartbeat.tick', 'gaffer/block-preview', function (response) {
        if (response && typeof response[settings.heartbeat] === 'string') {
            settings.nonce = response[settings.heartbeat];
        }
    });

    var withPreview = wp.compose.createHigherOrderComponent(function (BlockEdit) {
        return function (props) {
            var open = useState(false);
            if (blocks.indexOf(props.name) === -1) {
                return el(BlockEdit, props);
            }
            var type = wp.blocks.getBlockType(props.name);

            return el(wp.element.Fragment, null,
                el(BlockEdit, props),
                el(wp.blockEditor.BlockControls, null,
                    el(components.ToolbarGroup, null,
                        el(components.ToolbarButton, {
                            icon: 'visibility',
                            label: label.preview,
                            onClick: function () { open[1](true); },
                        }))),
                open[0] && el(Preview, {
                    clientId: props.clientId,
                    title: type ? type.title : props.name,
                    onClose: function () { open[1](false); },
                }));
        };
    }, 'withGafferBlockPreview');

    wp.hooks.addFilter('editor.BlockEdit', 'gaffer/block-preview', withPreview);
})(window.wp, window.gafferBlockPreview);
