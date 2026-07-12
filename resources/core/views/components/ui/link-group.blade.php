{{--
    Groups two or more related x-ui.link elements (e.g. reference links in a
    page-header actions slot) with a visible divider between them.

    Two accent-colored text links sitting side by side with only a flex gap
    between them read as one continuous phrase, not two separate links — the
    divider makes each link's boundary unambiguous without borrowing a
    button's task-implying weight. See the Link Dictionary in
    resources/core/views/AGENTS.md.
--}}
<div {{ $attributes->class([
    'inline-flex items-center divide-x divide-border-default',
    '[&>*]:px-3 [&>*:first-child]:pl-0 [&>*:last-child]:pr-0',
]) }}>
    {{ $slot }}
</div>
