@props(['status'])
@php $s = $status instanceof \App\Enums\AppointmentStatus ? $status : \App\Enums\AppointmentStatus::from($status); @endphp
<span class="badge" style="background: {{ $s->color() }}1a; color: {{ $s->color() }}">{{ $s->label() }}</span>
