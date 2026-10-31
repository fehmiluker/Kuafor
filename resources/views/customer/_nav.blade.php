<nav class="mb-6 flex flex-wrap gap-2" aria-label="Hesap menüsü">
    @foreach([['account.appointments', 'Randevularım'], ['account.favorites', 'Favorilerim'], ['account.reviews', 'Değerlendirmelerim'], ['account.profile', 'Profil & gizlilik']] as [$r, $l])
        <a href="{{ route($r) }}" class="chip {{ request()->routeIs($r) ? 'chip-active' : '' }}" @if(request()->routeIs($r)) aria-current="page" @endif>{{ $l }}</a>
    @endforeach
    <form method="POST" action="{{ route('logout') }}" class="ml-auto">@csrf<button class="btn-ghost btn-sm">Çıkış</button></form>
</nav>
