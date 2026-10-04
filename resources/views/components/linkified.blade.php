{{-- Renders user-supplied free text with any URL made clickable.

     Output is escaped before the anchors are added (see
     App\Helpers\TextRenderHelper), so this is safe with {!! !!}. Escape is
     deliberate: the text is untrusted, and the helper's only markup is an <a>
     whose href it built itself.

     Props:
       text      string  the raw text
       newlines  bool    render line breaks (default true, for note fields)  --}}
@props([
    'text',
    'newlines' => true,
])

{!! \App\Helpers\TextRenderHelper::linkify($text, $newlines) !!}
