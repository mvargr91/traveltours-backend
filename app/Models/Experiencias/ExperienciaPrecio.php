<?php

namespace App\Models\Experiencias;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ExperienciaPrecio extends Model
{
    use HasFactory;

    protected $table = 'experiencia_precios';

    protected $fillable = [
        'experiencia_id',
        'descripcion',
        'tipo',
        'cantidad',
        'precio',
        'estado',
    ];

    public static function obtenerColeccion($dto)
    {
        $query = DB::table('experiencia_precios')
            ->select(
                'experiencia_precios.id',
                'experiencia_precios.experiencia_id',
                'experiencia_precios.descripcion',
                'experiencia_precios.tipo',
                'experiencia_precios.cantidad',
                'experiencia_precios.precio',
                'experiencia_precios.estado',
            )
            ->where('experiencia_precios.experiencia_id', $dto['experiencia_id'])
            ->orderBy('experiencia_precios.precio', 'asc');

        return $query->get();
    }

    public static function cargar($id)
    {
        return ExperienciaPrecio::find($id);
    }

    public static function modificarOCrear($dto)
    {
        $precio = isset($dto['id']) ? ExperienciaPrecio::find($dto['id']) : new ExperienciaPrecio();
        $precio->fill($dto);
        $guardado = $precio->save();
        if (!$guardado) {
            throw new Exception('Ocurrió un error al intentar guardar el precio de la experiencia.');
        }

        return $precio;
    }

    public static function eliminar($id)
    {
        $precio = ExperienciaPrecio::find($id);
        return $precio->delete();
    }
}
