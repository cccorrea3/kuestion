<x-layouts.app title="Inicio">
    <x-empty-state icon="message-circle">
        Todavía no tienes preguntas vigiladas.
        Escribe tu primera pregunta y Kuestion la consultará a Kuaforia.
        Después, te avisará si la respuesta cambia con el tiempo.

        <x-slot:action>
            <a href="{{ route('questions.create') }}">
                <x-button>Escribe tu primera pregunta</x-button>
            </a>
        </x-slot:action>
    </x-empty-state>
</x-layouts.app>
