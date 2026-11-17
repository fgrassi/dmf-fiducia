<div class="p-2 text-center text-sm">
    @if (! $token)
        <strong>Não solicitado ainda</strong>
    @else
        <p><strong>Nenhum draft retornado</strong></p>
        <p class="mt-2"><strong>Solicitado em:</strong> {{ $token['created_at'] ?: '-' }}</p>
        <p class="mt-2"><strong>Link:</strong><br><a href="{{ $token['url'] }}" target="_blank" rel="noopener" class="break-all text-primary-600 underline">{{ $token['url'] }}</a></p>
        @if ($token['expires_at'])
            <p class="mt-2 text-xs text-gray-500">Expira em {{ $token['expires_at'] }}@if ($token['email_sent_to']) · e-mail enviado para {{ $token['email_sent_to'] }}@if ($token['email_sent_at']) em {{ $token['email_sent_at'] }}@endif @endif</p>
        @endif
    @endif
</div>
