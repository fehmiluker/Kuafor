{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url><loc>{{ route('home') }}</loc><changefreq>daily</changefreq></url>
    <url><loc>{{ route('search') }}</loc><changefreq>daily</changefreq></url>
    @foreach($cities as $c)<url><loc>{{ route('search', ['city' => $c->slug]) }}</loc><changefreq>daily</changefreq></url>
    @endforeach
    @foreach($tenants as $t)<url><loc>{{ route('salon.show', $t->slug) }}</loc><lastmod>{{ $t->updated_at?->toAtomString() }}</lastmod><changefreq>weekly</changefreq></url>
    @endforeach
</urlset>
