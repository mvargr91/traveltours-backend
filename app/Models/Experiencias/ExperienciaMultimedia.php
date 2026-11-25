<?php

namespace App\Models\Experiencias;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use App\Services\ArchivosService;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ExperienciaMultimedia extends Model
{
    use HasFactory;

    protected $table = 'experiencia_multimedia';

    // foto y video forman la galería; documento es un PDF (itinerario, políticas…) que el portal muestra como un
    // botón con el título como texto. Un video puede ser un enlace externo (YouTube); un documento siempre se sube.
    public const TIPOS = ['foto', 'video', 'documento'];

    public static function reglaArchivo(string $tipo): string
    {
        return match ($tipo) {
            'video' => ArchivosService::REGLA_VIDEO,
            'documento' => ArchivosService::REGLA_PDF,
            default => ArchivosService::REGLA_IMAGEN,
        };
    }

    public static function subcarpeta(string $tipo): string
    {
        return match ($tipo) {
            'video' => 'videos',
            'documento' => 'documentos',
            default => 'fotos',
        };
    }

    protected $fillable = [
        'experiencia_id',
        'tipo',
        'ruta_archivo',
        'titulo',
        'texto_alternativo',
        'orden',
        'estado',
    ];

    public static function obtenerColeccion($dto)
    {
        $query = DB::table('experiencia_multimedia')
            ->select(
                'experiencia_multimedia.id',
                'experiencia_multimedia.experiencia_id',
                'experiencia_multimedia.tipo',
                'experiencia_multimedia.ruta_archivo',
                'experiencia_multimedia.titulo',
                'experiencia_multimedia.texto_alternativo',
                'experiencia_multimedia.orden',
                'experiencia_multimedia.estado',
            )
            ->where('experiencia_multimedia.experiencia_id', $dto['experiencia_id'])
            ->orderBy('experiencia_multimedia.orden', 'asc');

        return $query->get();
    }

    public static function cargar($id)
    {
        return ExperienciaMultimedia::find($id);
    }

    public static function modificarOCrear($dto)
    {
        $media = isset($dto['id']) ? ExperienciaMultimedia::find($dto['id']) : new ExperienciaMultimedia();
        $media->fill($dto);
        $guardado = $media->save();
        if (!$guardado) {
            throw new Exception('Ocurrió un error al intentar guardar la multimedia de la experiencia.');
        }

        return $media;
    }

    public static function eliminar($id)
    {
        $media = ExperienciaMultimedia::find($id);

        if (Storage::exists($media->ruta_archivo)) {
            Storage::delete($media->ruta_archivo);
        }

        return $media->delete();
    }
}
