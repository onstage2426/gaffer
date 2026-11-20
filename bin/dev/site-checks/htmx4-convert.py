"""Mechanical htmx 2 -> 4 changes in a theme's templates (argv[1] = theme dir). Prints what changed."""
import pathlib, re, sys
theme = pathlib.Path(sys.argv[1])
for path in sorted([*theme.glob('views/**/*.twig'), *theme.glob('blocks/**/*.twig'), *theme.glob('woocommerce/**/*.php')]):
    s = path.read_text(); orig = s
    assert 'hx-disable=' not in s or 'hx-disabled-elt' not in s, f'{path}: has an htmx 2 hx-disable'
    s = s.replace('hx-disabled-elt=', 'hx-disable=')
    s = re.sub(r'hx-on::([a-z]+)-([a-z]+)(?=[\s=])', r'hx-on::\1:\2', s)
    # a form's wrapper that targets itself: move hx-target/hx-swap onto the <form> with hx-post
    m = re.search(r'<div id="(form-[a-z0-9-]+)" hx-target="this" hx-swap="(outerHTML)">', s)
    if m:
        s = s.replace(m.group(0), f'<div id="{m.group(1)}">')
        s, n = re.subn(r'(<form\b[^>]*?hx-post="\{\{ ajax_url\(\'Form\'\) \}\}")', rf'\1 hx-target="#{m.group(1)}" hx-swap="{m.group(2)}"', s, count=1)
        assert n == 1, f'{path}: no form with hx-post to Form'
    if s != orig:
        path.write_text(s)
        print('changed', path.relative_to(theme))
