<script>
    window.Numerosis = @js([
        'reverb' => [
            'key' => config('numerosis.broadcasting.reverb.key'),
            'host' => config('numerosis.broadcasting.reverb.host'),
            'port' => config('numerosis.broadcasting.reverb.port'),
            'scheme' => config('numerosis.broadcasting.reverb.scheme'),
        ],
        'tenantId' => tenancy()->initialized ? tenant('id') : null,
    ]);
</script>
