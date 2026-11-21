<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\User;
use App\Models\WhatsappQuickReply;
use App\Models\WhatsappRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/** What the audit trail can talk about: Spanish names of modules, actions and fields, subject aliases, labels and links. */
class ActivityCatalog
{
    public const MODULES = [
        'products' => 'Productos', 'categories' => 'Categorías', 'inventory' => 'Inventario', 'promotions' => 'Promociones', 'coupons' => 'Cupones',
        'orders' => 'Pedidos', 'customers' => 'Clientes', 'whatsapp' => 'WhatsApp', 'users' => 'Usuarios', 'activity' => 'Historial', 'auth' => 'Accesos',
    ];

    /** Actions each module can record (the action filter follows the chosen module). */
    public const MODULE_ACTIONS = [
        'products' => ['created', 'updated', 'activated', 'deactivated'],
        'categories' => ['created', 'updated', 'activated', 'deactivated'],
        'inventory' => ['stock_adjusted', 'stock_returned'],
        'promotions' => ['created', 'updated', 'activated', 'deactivated', 'deleted'],
        'coupons' => ['created', 'updated', 'activated', 'deactivated', 'deleted'],
        'orders' => ['status_changed', 'updated'],
        'customers' => ['marketing_opted_out', 'exported'],
        'whatsapp' => ['created', 'taken', 'assigned', 'resolved', 'reopened', 'notes_updated', 'quick_reply_updated'],
        'users' => ['created', 'updated', 'role_changed', 'deactivated', 'reactivated', 'password_reset', 'password_link_created', 'password_changed'],
        'activity' => ['exported'],
        'auth' => ['login', 'login_failed', 'logout'],
    ];

    public const ACTIONS = [
        'created' => 'Creó', 'updated' => 'Actualizó', 'deleted' => 'Eliminó', 'activated' => 'Activó', 'deactivated' => 'Desactivó',
        'status_changed' => 'Cambió estado', 'stock_adjusted' => 'Ajustó stock', 'stock_returned' => 'Devolvió stock', 'exported' => 'Exportó',
        'marketing_opted_out' => 'Marcó "no quiere promociones"', 'taken' => 'Tomó', 'assigned' => 'Asignó', 'resolved' => 'Resolvió', 'reopened' => 'Reabrió',
        'notes_updated' => 'Actualizó notas', 'role_changed' => 'Cambió el rol', 'password_reset' => 'Restableció contraseña', 'password_link_created' => 'Creó enlace de contraseña', 'password_changed' => 'Cambió su contraseña', 'reactivated' => 'Reactivó', 'quick_reply_updated' => 'Editó respuesta rápida',
        'login' => 'Inició sesión', 'login_failed' => 'Intento fallido', 'logout' => 'Cerró sesión',
    ];

    public const FIELDS = [
        'name' => 'Nombre', 'category_id' => 'Categoría', 'short_description' => 'Descripción corta', 'description' => 'Descripción', 'is_published' => 'Publicado',
        'is_active' => 'Activo', 'price_cop' => 'Precio (COP)', 'weight_grams' => 'Peso (g)', 'grind_type' => 'Molienda', 'stock_quantity' => 'Stock',
        'discount_type' => 'Tipo de descuento', 'discount_value' => 'Valor del descuento', 'starts_at' => 'Inicio', 'ends_at' => 'Fin', 'variant_ids' => 'Presentaciones',
        'code' => 'Código', 'max_discount_cop' => 'Descuento máximo (COP)', 'min_subtotal_cop' => 'Compra mínima (COP)', 'max_uses' => 'Usos máximos',
        'exclude_promoted_items' => 'Excluye productos en promoción', 'status' => 'Estado', 'assigned_to' => 'Responsable', 'internal_notes' => 'Notas internas',
        'resolution_note' => 'Nota de resolución', 'title' => 'Título', 'body' => 'Texto', 'topic' => 'Tema', 'marketing_opt_out_at' => 'No quiere promociones desde',
        'product_id' => 'Producto', 'payment_status' => 'Estado del pago', 'username' => 'Usuario', 'role' => 'Rol', 'assigned_delivery_user_id' => 'Repartidor',
    ];

    /** Stable aliases stored in `subject_type` (never PHP class names, which can be renamed). */
    private const TYPES = [
        'order' => Order::class, 'product' => Product::class, 'product_variant' => ProductVariant::class, 'category' => Category::class,
        'promotion' => Promotion::class, 'coupon' => Coupon::class, 'customer' => Customer::class,
        'whatsapp_request' => WhatsappRequest::class, 'whatsapp_quick_reply' => WhatsappQuickReply::class, 'user' => User::class,
    ];

    public static function typeOf(Model $model): ?string
    {
        $alias = array_search($model::class, self::TYPES, true);

        return $alias === false ? null : $alias;
    }

    public static function labelFor(Model $model): string
    {
        $label = match (true) {
            $model instanceof Order => 'Pedido: '.$model->reference,
            $model instanceof Product => 'Producto: '.$model->name,
            $model instanceof ProductVariant => 'Variante: '.($model->product?->name ?? 'Producto').' · '.$model->name,
            $model instanceof Category => 'Categoría: '.$model->name,
            $model instanceof Promotion => 'Promoción #'.$model->id.': '.($model->product?->name ?? 'producto'),
            $model instanceof Coupon => 'Cupón: '.$model->code,
            $model instanceof Customer => 'Cliente: '.(ActivityRedactor::maskEmail($model->email) ?? '[valor no válido]'),
            $model instanceof WhatsappRequest => 'Solicitud de WhatsApp: '.$model->name,
            $model instanceof WhatsappQuickReply => 'Respuesta rápida: '.$model->title,
            $model instanceof User => 'Usuario: '.$model->name.' (@'.$model->username.')',
            default => class_basename($model).' #'.$model->getKey(),
        };

        return mb_strimwidth($label, 0, 191, '…');
    }

    /**
     * Link to each resource that still exists, keyed "type:id". Only rows that still exist get a link.
     * Products, promotions, coupons and categories have no page per record: they open the editor or their module list.
     *
     * @param  Collection<int, ActivityLog>  $rows
     * @return array<string, string>
     */
    public static function links(Collection $rows): array
    {
        $links = [];
        foreach ($rows->whereNotNull('subject_type')->whereNotNull('subject_id')->groupBy('subject_type') as $type => $group) {
            $class = self::TYPES[$type] ?? null;
            if (! $class) {
                continue;
            }
            $ids = $group->pluck('subject_id')->unique()->all();
            if ($type === 'product_variant') {
                foreach (ProductVariant::query()->whereIn('id', $ids)->get(['id', 'product_id']) as $variant) {
                    $links["$type:{$variant->id}"] = "/admin/products?editor=product&product={$variant->product_id}";
                }

                continue;
            }
            foreach ($class::query()->whereIn('id', $ids)->pluck('id') as $id) {
                $links["$type:$id"] = match ($type) {
                    'order' => "/admin/orders/$id", 'customer' => "/admin/customers/$id", 'whatsapp_request' => "/admin/whatsapp/$id",
                    'product' => "/admin/products?editor=product&product=$id", 'category' => '/admin/products/categories', 'promotion' => '/admin/promotions',
                    'coupon' => '/admin/coupons', 'whatsapp_quick_reply' => '/admin/whatsapp', 'user' => "/admin/users/$id", default => '',
                };
            }
        }

        return array_filter($links);
    }
}
