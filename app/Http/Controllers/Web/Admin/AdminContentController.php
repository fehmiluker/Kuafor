<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\HomeContent;
use App\Models\ServiceCategory;
use App\Services\Audit\AuditLogger;
use App\Services\Catalog\TenantCatalogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AdminContentController extends Controller
{
    public const KINDS = ['banner' => 'Bannerlar', 'popular' => 'Popüler Hizmetler'];

    public const ICONS = ['scissors' => 'Makas', 'razor' => 'Berber', 'face' => 'Güzellik', 'polish' => 'Tırnak', 'lotus' => 'Masaj', 'brush' => 'Fırça', 'star' => 'Yıldız', 'store' => 'İşletme'];

    private function need(Request $request): void
    {
        abort_unless($request->user()->hasPlatformPermission('platform.settings'), 403);
    }

    public function index(Request $request, string $kind)
    {
        $this->need($request);
        abort_unless(isset(self::KINDS[$kind]), 404);

        return view('admin.content.index', ['kind' => $kind, 'title' => self::KINDS[$kind], 'items' => HomeContent::where('kind', $kind)->orderBy('sort_order')->orderBy('id')->get()]);
    }

    public function form(Request $request, string $kind, ?HomeContent $item = null)
    {
        $this->need($request);
        abort_unless(isset(self::KINDS[$kind]) && (! $item || $item->kind === $kind), 404);

        return view('admin.content.form', ['kind' => $kind, 'title' => self::KINDS[$kind], 'item' => $item, 'categories' => ServiceCategory::whereNull('tenant_id')->where('status', 'active')->orderBy('sort_order')->get()]);
    }

    public function save(Request $request, string $kind, TenantCatalogService $images, ?HomeContent $item = null)
    {
        $this->need($request);
        abort_unless(isset(self::KINDS[$kind]) && (! $item || $item->kind === $kind), 404);
        $d = $request->validate([
            'title' => ['required', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:500'],
            'button_text' => ['nullable', 'string', 'max:80'], 'target_url' => ['nullable', 'string', 'max:500', function ($attribute, $value, $fail) {
                $relative = str_starts_with($value, '/') && ! str_starts_with($value, '//');
                $external = filter_var($value, FILTER_VALIDATE_URL) && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
                if ((! $relative && ! $external) || str_contains($value, chr(92)) || preg_match('/[\x00-\x20]/', $value)) {
                    $fail('Geçerli bir http/https veya site içi bağlantı girin.');
                }
            }],
            'category_id' => ['nullable', Rule::exists('service_categories', 'id')->whereNull('tenant_id')->where('status', 'active')],
            'sort_order' => ['required', 'integer', 'between:0,65535'], 'active' => ['nullable', 'boolean'],
            'image' => [$item ? 'nullable' : 'required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:max_width=5000,max_height=5000'],
            'image_mobile' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:max_width=3000,max_height=4000'],
        ]);
        $item ??= new HomeContent(['kind' => $kind]);
        $item->fill(collect($d)->except(['image', 'image_mobile'])->all());
        $item->active = $request->boolean('active');
        $old = [];
        foreach (['image' => 'image_path', 'image_mobile' => 'image_mobile_path'] as $field => $column) {
            if ($request->hasFile($field)) {
                $old[] = $item->{$column};
                $item->{$column} = $images->storeImage($request->file($field), 'home');
            }
        }
        if ($request->boolean('remove_mobile')) {
            $old[] = $item->image_mobile_path;
            $item->image_mobile_path = null;
        }
        $item->save();
        Storage::disk('public')->delete(array_filter($old));
        app(AuditLogger::class)->log('admin.home_content_saved', $item, [], ['kind' => $kind, 'title' => $item->title]);

        return redirect()->route('admin.content.index', $kind)->with('status', 'İçerik kaydedildi.');
    }

    public function destroy(Request $request, string $kind, HomeContent $item)
    {
        $this->need($request);
        abort_unless(isset(self::KINDS[$kind]) && $item->kind === $kind, 404);
        $paths = array_filter([$item->image_path, $item->image_mobile_path]);
        app(AuditLogger::class)->log('admin.home_content_deleted', $item, [], ['kind' => $kind]);
        $item->delete();
        Storage::disk('public')->delete($paths);

        return back()->with('status', 'İçerik silindi.');
    }

    public function categories(Request $request)
    {
        $this->need($request);

        return view('admin.categories', ['categories' => ServiceCategory::whereNull('tenant_id')->orderBy('sort_order')->orderBy('id')->get(), 'icons' => self::ICONS]);
    }

    public function saveCategory(Request $request, TenantCatalogService $images, ?ServiceCategory $category = null)
    {
        $this->need($request);
        abort_if($category && $category->tenant_id !== null, 404);
        $d = $request->validate([
            'name' => ['required', 'string', 'max:100'], 'slug' => ['required', 'alpha_dash', 'max:100', Rule::unique('service_categories', 'slug')->whereNull('tenant_id')->ignore($category?->id)],
            'home_label' => ['nullable', 'string', 'max:100'], 'icon' => ['required', Rule::in(array_keys(self::ICONS))],
            'tone' => ['required', Rule::in(['k1', 'k2', 'k3', 'k4', 'k5'])], 'sort_order' => ['required', 'integer', 'between:0,65535'],
            'status' => ['required', Rule::in(['active', 'inactive'])], 'show_on_home' => ['nullable', 'boolean'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=2000,max_height=2000'],
        ]);
        $category ??= new ServiceCategory;
        $oldSlug = $category->slug;
        $category->fill(collect($d)->except('image')->all());
        $category->show_on_home = $request->boolean('show_on_home');
        $old = null;
        if ($request->hasFile('image')) {
            $old = $category->image_path;
            $category->image_path = $images->storeImage($request->file('image'), 'categories');
        }
        if ($request->boolean('remove_image')) {
            $old = $category->image_path;
            $category->image_path = null;
        }
        $category->save();
        if ($oldSlug && $oldSlug !== $category->slug) {
            DB::table('home_contents')->where('target_url', '/salonlar?kategori='.$oldSlug)->update(['target_url' => '/salonlar?kategori='.$category->slug]);
        }
        if ($old) {
            Storage::disk('public')->delete($old);
        }
        app(AuditLogger::class)->log('admin.category_saved', $category, [], ['slug' => $category->slug]);

        return back()->with('status', 'Kategori kaydedildi.');
    }

    public function deleteCategory(Request $request, ServiceCategory $category)
    {
        $this->need($request);
        abort_if($category->tenant_id !== null, 404);
        $hasServices = DB::table('services')->where('category_id', $category->id)->exists();
        $d = $request->validate(['replacement_id' => [$hasServices ? 'required' : 'nullable', 'integer', Rule::notIn([$category->id]), Rule::exists('service_categories', 'id')->whereNull('tenant_id')->where('status', 'active')]]);
        DB::transaction(function () use ($category, $d) {
            $replacement = isset($d['replacement_id']) ? ServiceCategory::find($d['replacement_id']) : null;
            DB::table('home_contents')->where('target_url', '/salonlar?kategori='.$category->slug)->update(['target_url' => $replacement ? '/salonlar?kategori='.$replacement->slug : '/salonlar']);
            DB::table('services')->where('category_id', $category->id)->update(['category_id' => $d['replacement_id'] ?? null]);
            DB::table('home_contents')->where('category_id', $category->id)->update(['category_id' => $d['replacement_id'] ?? null]);
            app(AuditLogger::class)->log('admin.category_deleted', $category, [], ['replacement_id' => $d['replacement_id'] ?? null]);
            $category->delete();
        });
        if ($category->image_path) {
            Storage::disk('public')->delete($category->image_path);
        }

        return back()->with('status', 'Kategori silindi.');
    }
}
