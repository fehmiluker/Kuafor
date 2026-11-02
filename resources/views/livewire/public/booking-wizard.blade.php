<div class="mx-auto max-w-3xl">
    <div class="mb-4 flex items-center justify-between">
        <div><p class="text-sm text-slate-500"><a href="{{ route('salon.show', $tenant->slug) }}" class="hover:underline">{{ $tenant->name }}</a></p><h1 class="text-xl font-bold">Randevu al</h1></div>
        @if($step > 1 && $step < 7)<button type="button" wire:click="back" class="btn-ghost btn-sm">‹ Geri</button>@endif
    </div>
    @php $labels = ['Şube', 'Hizmet', 'Personel', 'Tarih & saat', 'Bilgileriniz', 'Özet']; @endphp
    @if($step < 7)
        <ol class="mb-6 flex gap-1" aria-label="Adımlar">
            @foreach($labels as $i => $l)
                <li class="flex-1"><div class="h-1.5 rounded {{ $step > $i ? 'bg-brand-600' : 'bg-slate-200' }}"></div><span class="mt-1 hidden text-xs sm:block {{ $step === $i + 1 ? 'font-semibold text-brand-700' : 'text-slate-500' }}" @if($step === $i + 1) aria-current="step" @endif>{{ $l }}</span></li>
            @endforeach
        </ol>
    @endif

    @error('booking')<div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-900 ring-1 ring-red-200" role="alert">{{ $message }}</div>@enderror
    <div wire:loading.delay class="mb-3 text-sm text-slate-500" role="status">Yükleniyor…</div>

    @if($step === 1)
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach($branches as $b)
                <button type="button" wire:click="selectBranch({{ $b->id }})" class="card p-4 text-left hover:ring-brand-500">
                    <p class="font-semibold">{{ $b->name }}</p><p class="text-sm text-slate-500">{{ $b->address }}</p>
                    <p class="text-sm text-slate-500">{{ collect([$b->district?->name, $b->city?->name])->filter()->implode(', ') }}</p>
                </button>
            @endforeach
        </div>
    @elseif($step === 2)
        @if($areas->isNotEmpty())
            <fieldset class="mb-4">
                <legend class="mb-2 text-sm font-semibold">Hizmet nerede verilsin?</legend>
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" wire:click="setLocation('salon')" aria-pressed="{{ $home ? 'false' : 'true' }}" class="card p-3 text-left {{ $home ? '' : 'ring-2 ring-brand-600' }}"><span class="block font-semibold">💈 Salonda</span><span class="block text-xs text-slate-500">{{ $branch->name }}</span></button>
                    <button type="button" wire:click="setLocation('address')" aria-pressed="{{ $home ? 'true' : 'false' }}" class="card p-3 text-left {{ $home ? 'ring-2 ring-brand-600' : '' }}"><span class="block font-semibold">🏠 Adresimde</span><span class="block text-xs text-slate-500">Ev veya işyeri</span></button>
                </div>
                @if($home)
                    <p class="mt-2 rounded-lg bg-brand-50 p-3 text-xs text-slate-700">Gidilen ilçeler: {{ $areas->map(fn ($a) => $a->district?->name.($a->travel_fee && ! $a->travel_fee->isZero() ? ' (+'.$a->travel_fee->format().')' : ' (ulaşım ücretsiz)'))->implode(', ') }}.
                        @if($branch->home_min_total) En düşük hizmet tutarı {{ $branch->home_min_total->format() }}. @endif Ödeme hizmet sonrası yapılır.</p>
                @endif
            </fieldset>
        @endif
        @if($services->isEmpty())
            <x-empty title="Bu şubede online randevuya açık hizmet yok">Lütfen salonu arayın veya başka bir şube seçin.</x-empty>
        @else
            <fieldset><legend class="sr-only">Hizmet seçin (birden fazla seçebilirsiniz)</legend>
                <ul class="space-y-2">
                    @foreach($services as $s)
                        @php $on = in_array($s->id, $serviceIds, true); @endphp
                        <li><button type="button" wire:click="toggleService({{ $s->id }})" aria-pressed="{{ $on ? 'true' : 'false' }}" class="card flex w-full items-center justify-between gap-3 p-4 text-left {{ $on ? 'ring-2 ring-brand-600' : '' }}">
                            <span><span class="font-medium">{{ $s->name }}</span><span class="block text-xs text-slate-500">{{ $s->effective_duration }} dk @if($s->category) · {{ $s->category->name }}@endif</span></span>
                            <span class="flex items-center gap-3"><span class="font-semibold">{{ $s->effective_price->format() }}</span><span aria-hidden="true" class="inline-flex h-6 w-6 items-center justify-center rounded border {{ $on ? 'border-brand-600 bg-brand-600 text-white' : 'border-slate-300' }}">{{ $on ? '✓' : '' }}</span></span>
                        </button></li>
                    @endforeach
                </ul>
            </fieldset>
            @error('serviceIds')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
            <div class="sticky bottom-0 mt-4 flex items-center justify-between rounded-xl bg-white p-4 ring-1 ring-slate-200">
                <span class="text-sm">{{ count($serviceIds) }} hizmet · {{ $duration }} dk · <strong>{{ $subtotal->format() }}</strong>@if($belowMinimum && $serviceIds)<span class="block text-xs text-amber-700">En düşük tutar {{ $branch->home_min_total->format() }}</span>@endif</span>
                <button type="button" wire:click="toEmployees" class="btn-primary" @disabled(!$serviceIds)>Devam</button>
            </div>
        @endif
    @elseif($step === 3)
        <div class="grid gap-3 sm:grid-cols-2">
            <button type="button" wire:click="selectEmployee(null)" class="card p-4 text-left hover:ring-brand-500"><p class="font-semibold">Fark etmez</p><p class="text-sm text-slate-500">En uygun personel otomatik atanır.</p></button>
            @forelse($employees as $e)
                <button type="button" wire:click="selectEmployee({{ $e->id }})" class="card flex items-center gap-3 p-4 text-left hover:ring-brand-500">
                    <span aria-hidden="true" class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-brand-100 font-semibold text-brand-700">{{ mb_substr($e->display_name, 0, 1) }}</span>
                    <span class="font-semibold">{{ $e->display_name }}</span>
                </button>
            @empty
                <p class="text-sm text-slate-500">{{ $home ? 'Seçtiğiniz hizmetler için adrese gelebilecek personel yok.' : 'Seçtiğiniz hizmetlerin hepsini yapabilen personel yok.' }} Hizmet seçimini değiştirin.</p>
            @endforelse
        </div>
    @elseif($step === 4)
        <div class="card p-4">
            <div class="flex items-center justify-between gap-2">
                <button type="button" wire:click="shiftDate(-1)" class="btn-secondary btn-sm" aria-label="Önceki gün">‹</button>
                <label for="w-date" class="sr-only">Tarih</label>
                <input id="w-date" type="date" wire:model.live="date" min="{{ now($branch->timezone)->toDateString() }}" max="{{ now($branch->timezone)->addDays($settings->maxHorizonDays)->toDateString() }}" class="input max-w-48 text-center">
                <button type="button" wire:click="shiftDate(1)" class="btn-secondary btn-sm" aria-label="Sonraki gün">›</button>
            </div>
            <p class="mt-2 text-center text-sm text-slate-600">{{ \Carbon\CarbonImmutable::parse($date)->locale('tr')->translatedFormat('d F Y, l') }} @if($employee) · {{ $employee->display_name }} @else · Fark etmez @endif</p>
            @if($slotError)<p class="mt-4 text-sm text-red-600">{{ $slotError }}</p>@endif
            @if(empty($slots))
                <p class="mt-6 text-center text-sm text-slate-500">Bu gün için uygun saat yok. Başka bir gün deneyin.</p>
            @else
                <div class="mt-4 grid grid-cols-3 gap-2 sm:grid-cols-6" role="list" aria-label="Uygun saatler">
                    @foreach($slots as $slot)
                        @php $t = $slot['start']->setTimezone($branch->timezone)->format('H:i'); @endphp
                        <button type="button" role="listitem" wire:click="selectTime('{{ $t }}')" class="chip justify-center {{ $time === $t ? 'chip-active' : '' }}">{{ $t }}</button>
                    @endforeach
                </div>
            @endif
        </div>
    @elseif($step === 5)
        <form wire:submit="toSummary" class="card space-y-4 p-5">
            <div class="hidden" aria-hidden="true"><label>Web sitesi <input type="text" wire:model="website" tabindex="-1" autocomplete="off"></label></div>
            <div><label for="w-name" class="label">Ad soyad *</label><input id="w-name" wire:model="fullName" class="input" autocomplete="name" required>@error('fullName')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
            <div><label for="w-phone" class="label">Cep telefonu *</label><input id="w-phone" wire:model="phone" type="tel" inputmode="tel" placeholder="05xx xxx xx xx" class="input" autocomplete="tel" required>@error('phone')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
            <div><label for="w-email" class="label">E-posta (isteğe bağlı)</label><input id="w-email" wire:model="email" type="email" class="input" autocomplete="email">@error('email')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
            @if($home)
                <fieldset class="space-y-3 rounded-xl bg-slate-50 p-4">
                    <legend class="text-sm font-semibold">🏠 Hizmet adresi</legend>
                    <div class="flex gap-4 text-sm">
                        @foreach(\App\Models\AppointmentAddress::PLACE_TYPES as $k => $l)<label class="flex items-center gap-2"><input type="radio" wire:model="placeType" value="{{ $k }}"> {{ $l }}</label>@endforeach
                    </div>
                    <div><label for="w-district" class="label">İlçe *</label>
                        <select id="w-district" wire:model.live="districtId" class="input" required>
                            <option value="">Seçin</option>
                            @foreach($areas as $a)<option value="{{ $a->district_id }}">{{ $a->district?->name }}, {{ $a->district?->city?->name }}{{ $a->travel_fee && ! $a->travel_fee->isZero() ? ' (+'.$a->travel_fee->format().')' : '' }}</option>@endforeach
                        </select>@error('districtId')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
                    <div><label for="w-addr" class="label">Açık adres (mahalle, cadde/sokak, no) *</label><input id="w-addr" wire:model="addressLine" class="input" autocomplete="street-address" maxlength="300" required>@error('addressLine')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
                    <div><label for="w-addr2" class="label">Kat / daire / adres tarifi</label><input id="w-addr2" wire:model="addressDetail" class="input" maxlength="300" placeholder="Örn. 3. kat, daire 8, zil: Kaya">@error('addressDetail')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
                    <p class="text-xs text-slate-500">Adresiniz yalnızca bu randevu için salonla paylaşılır ve randevudan sonra silinir.</p>
                </fieldset>
            @endif
            <div><label for="w-note" class="label">Not (isteğe bağlı)</label><textarea id="w-note" wire:model="note" rows="2" class="input" maxlength="1000"></textarea></div>
            @if(($settings?->requirePhoneVerification || $home) && !(auth()->user()?->phone_verified_at))
                <div class="rounded-lg bg-slate-50 p-3">
                    <p class="text-sm">{{ $home ? 'Adrese hizmet için telefon numaranızı doğrulamanız gerekiyor.' : 'Bu salon telefon doğrulaması istiyor.' }}</p>
                    <div class="mt-2 flex gap-2"><input wire:model="code" inputmode="numeric" maxlength="6" placeholder="6 haneli kod" class="input max-w-40" aria-label="Doğrulama kodu"><button type="button" wire:click="sendCode" class="btn-secondary">{{ $codeSent ? 'Tekrar gönder' : 'Kod gönder' }}</button></div>
                </div>
            @endif
            <label class="flex items-start gap-2 text-sm"><input type="checkbox" wire:model="kvkk" class="mt-1" required> <span><a href="{{ route('kvkk') }}" target="_blank" class="text-brand-700 underline">Aydınlatma metnini</a> okudum. *</span></label>
            @error('kvkk')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
            <label class="flex items-start gap-2 text-sm"><input type="checkbox" wire:model="whatsappOptIn" class="mt-1"> <span>Randevu bildirimleri (onay, değişiklik, hatırlatma) WhatsApp üzerinden gönderilsin.</span></label>
            <label class="flex items-start gap-2 text-sm"><input type="checkbox" wire:model="marketingOptIn" class="mt-1"> <span>Kampanya ve duyurular için ticari elektronik ileti almak istiyorum (isteğe bağlı).</span></label>
            <button class="btn-primary w-full">Devam</button>
        </form>
    @elseif($step === 6)
        <div class="card space-y-3 p-5">
            <h2 class="font-semibold">Randevu özeti</h2>
            <dl class="grid grid-cols-3 gap-2 text-sm">
                <dt class="text-slate-500">{{ $home ? 'Yer' : 'Şube' }}</dt><dd class="col-span-2">@if($home)🏠 {{ \App\Models\AppointmentAddress::PLACE_TYPES[$placeType] ?? '' }}: {{ $addressLine }}@if($addressDetail) ({{ $addressDetail }})@endif, {{ $areas[$districtId]?->district?->name ?? '' }} <span class="block text-xs text-slate-500">{{ $branch->name }} şubesinden gelinecek</span>@else{{ $branch->name }}@endif</dd>
                <dt class="text-slate-500">Hizmetler</dt><dd class="col-span-2">{{ $selected->pluck('name')->implode(', ') }} ({{ $duration }} dk)</dd>
                <dt class="text-slate-500">Personel</dt><dd class="col-span-2">{{ $employee?->display_name ?? 'Fark etmez' }}</dd>
                <dt class="text-slate-500">Tarih</dt><dd class="col-span-2 font-medium">{{ \Carbon\CarbonImmutable::parse($date)->locale('tr')->translatedFormat('d F Y l') }}, {{ $time }}</dd>
                <dt class="text-slate-500">Ad / Tel</dt><dd class="col-span-2">{{ $fullName }} · {{ $phone }}</dd>
                @if($home)<dt class="text-slate-500">Hizmet tutarı</dt><dd class="col-span-2">{{ $subtotal->format() }}</dd><dt class="text-slate-500">Ulaşım</dt><dd class="col-span-2">{{ $travelFee->isZero() ? 'Ücretsiz' : $travelFee->format() }}</dd>@endif
                <dt class="text-slate-500">Toplam</dt><dd class="col-span-2 font-semibold">{{ $total->format() }}</dd>
            </dl>
            <p class="rounded-lg bg-slate-50 p-3 text-sm">💳 Online ödeme veya kapora alınmaz; ödemeyi {{ $home ? "hizmetten sonra" : "salonda" }} yaparsınız.
                @if($settings->approvalMode->value === 'manual' || ($home && $branch->home_requires_approval)) Talebiniz salon onayından sonra kesinleşir. @else Randevunuz anında onaylanır. @endif</p>
            <button type="button" wire:click="confirm" wire:loading.attr="disabled" class="btn-primary w-full">Randevuyu onayla</button>
        </div>
    @elseif($step === 7 && $result)
        <div class="card p-6 text-center" role="status">
            <p class="text-4xl" aria-hidden="true">{{ $result['status'] === 'confirmed' ? '✅' : '⏳' }}</p>
            <h2 class="mt-2 text-xl font-bold">{{ $result['status'] === 'confirmed' ? 'Randevunuz onaylandı' : 'Talebiniz alındı' }}</h2>
            <p class="mt-1 text-sm text-slate-600">Randevu no: <strong>{{ $result['reference'] }}</strong> · Durum: {{ $result['status_label'] }}</p>
            @if($result['manage_url'])
                <p class="mt-4 text-sm">Randevunuzu görüntülemek veya iptal etmek için bu bağlantıyı saklayın:</p>
                <a href="{{ $result['manage_url'] }}" class="btn-secondary mt-2 break-all">Randevumu yönet</a>
            @endif
            @auth
                @if($result['favorite'] ?? false)
                    <p class="mt-4 text-sm text-emerald-700">♥ {{ $tenant->name }} favorilerinizde; bir dahaki sefere "Favoriler"den tek dokunuşla randevu alabilirsiniz.</p>
                @else
                    <div class="mt-4 rounded-xl bg-brand-50 p-4 text-sm">
                        <p>Bir dahaki sefere daha hızlı randevu almak için <strong>{{ $tenant->name }}</strong> salonunu favorilerinize ekleyin.</p>
                        <button type="button" wire:click="addFavorite" class="btn-primary btn-sm mt-2">♡ Favorilere ekle</button>
                    </div>
                @endif
            @endauth
            @guest<p class="mt-4 text-xs text-slate-500">Bu salon bu cihazda hatırlanır; ana sayfadan tekrar randevu alabilirsiniz. Hesap açarak tüm randevularınızı ve favori salonlarınızı tek yerden yönetebilirsiniz. <a href="{{ route('register') }}" class="text-brand-700 underline">Üye ol</a></p>@endguest
        </div>
    @endif
</div>
