@props(['question'])

@php
    // 14.1 — Ego-network de 1 salto: pregunta central + vecinos (salientes y
    // entrantes directos), mismo patrón de datos que RelationsPanel/BacklinksPanel.
    // SVG generado server-side, sin librerías de grafo. Solo relaciones del
    // usuario logueado (el detalle ya está scoped por current_user_id()).
    $outbound = $question->outboundRelations()->with('target:id,question_text')->get();
    $inbound = $question->inboundRelations()->with('source:id,question_text')->get();

    $nodes = [['id' => $question->id, 'text' => $question->question_text]];
    $index = [$question->id => 0];
    $edges = [];

    foreach ($outbound as $rel) {
        if (! isset($index[$rel->target_question_id])) {
            $index[$rel->target_question_id] = count($nodes);
            $nodes[] = ['id' => $rel->target->id, 'text' => $rel->target->question_text];
        }
        $edges[] = ['node' => $index[$rel->target_question_id], 'label' => $rel->label];
    }

    foreach ($inbound as $rel) {
        if (! isset($index[$rel->source_question_id])) {
            $index[$rel->source_question_id] = count($nodes);
            $nodes[] = ['id' => $rel->source->id, 'text' => $rel->source->question_text];
        }
        $edges[] = ['node' => $index[$rel->source_question_id], 'label' => $rel->label];
    }

    // Layout radial: centro fijo, vecinos en círculo uniforme.
    $cx = 160;
    $cy = 160;
    $radius = 105;
    $neighbors = array_slice($nodes, 1);
    $positions = [];
    $count = count($neighbors);

    foreach ($neighbors as $i => $node) {
        $angle = (2 * M_PI * $i / max($count, 1)) - (M_PI / 2);
        $positions[$node['id']] = [
            'x' => round($cx + $radius * cos($angle)),
            'y' => round($cy + $radius * sin($angle)),
        ];
    }
@endphp

@if (count($edges) > 0)
    <div class="bg-surface rounded-xl shadow-sm border border-border p-5">
        <h2 class="text-sm font-bold text-text mb-3 flex items-center gap-2">
            <i data-lucide="share-2" class="w-4 h-4 text-primary"></i>
            Red de relaciones
        </h2>
        <div class="flex items-center justify-center">
            <svg viewBox="0 0 320 320" class="w-full max-w-md h-auto" role="img" aria-label="Red de relaciones de {{ $question->question_text }}">
                {{-- Aristas con el label de la relación (tooltip). --}}
                @foreach ($edges as $edge)
                    @php $p = $positions[$nodes[$edge['node']]['id']]; @endphp
                    <line x1="{{ $cx }}" y1="{{ $cy }}" x2="{{ $p['x'] }}" y2="{{ $p['y'] }}"
                        stroke="#d4d4d8" stroke-width="1.5">
                        <title>{{ $edge['label'] }}</title>
                    </line>
                @endforeach

                {{-- Vecinos: nodos clicables que navegan al detalle. --}}
                @foreach ($neighbors as $node)
                    @php $p = $positions[$node['id']]; @endphp
                    <a href="{{ route('questions.show', $node['id']) }}" wire:navigate>
                        <circle cx="{{ $p['x'] }}" cy="{{ $p['y'] }}" r="18" fill="#ffedd5" stroke="#fb923c" stroke-width="1.5" />
                        <text x="{{ $p['x'] }}" y="{{ $p['y'] + 5 }}" text-anchor="middle" font-size="12" fill="#9a3412" font-weight="600">{{ mb_strtoupper(mb_substr($node['text'], 0, 1)) }}</text>
                        <text x="{{ $p['x'] }}" y="{{ $p['y'] + 32 }}" text-anchor="middle" font-size="9" fill="#71717a">{{ mb_strimwidth($node['text'], 0, 22, '…') }}</text>
                    </a>
                @endforeach

                {{-- Nodo central (esta pregunta). --}}
                <circle cx="{{ $cx }}" cy="{{ $cy }}" r="24" fill="#0d9488" />
                <text x="{{ $cx }}" y="{{ $cy + 5 }}" text-anchor="middle" font-size="13" fill="#ffffff" font-weight="700">{{ mb_strtoupper(mb_substr($question->question_text, 0, 1)) }}</text>
                <text x="{{ $cx }}" y="{{ $cy + 38 }}" text-anchor="middle" font-size="9" fill="#71717a">esta pregunta</text>
            </svg>
        </div>
        <p class="text-xs text-text-muted mt-3 flex items-center gap-1">
            <i data-lucide="info" class="w-3.5 h-3.5"></i>
            {{ count($edges) }} {{ str('relación')->plural(count($edges)) }} directa{{ count($edges) === 1 ? '' : 's' }}.
            Hacé clic en un nodo para ver esa pregunta.
        </p>
    </div>
@endif
