<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TurismoDatosSeeder extends Seeder
{
    public function run()
    {
        $ahora = Carbon::now();
        $audit = [
            'usuario_creacion_id' => 1,
            'usuario_creacion_nombre' => 'SuperUser',
            'usuario_modificacion_id' => 1,
            'usuario_modificacion_nombre' => 'SuperUser',
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ];

        // 1. Categorías
        $categorias = [
            ['nombre' => 'Vida Nocturna', 'slug' => 'vida-nocturna', 'descripcion' => 'Bares, clubes y fiestas seguras y vibrantes para la comunidad LGTBIQ+.', 'orden' => 1],
            ['nombre' => 'Cultura y Arte', 'slug' => 'cultura-y-arte', 'descripcion' => 'Museos, galerías, historia diversa y expresiones artísticas.', 'orden' => 2],
            ['nombre' => 'Naturaleza y Aventura', 'slug' => 'naturaleza-y-aventura', 'descripcion' => 'Senderismo, ecoturismo y adrenalina en los paisajes más bellos.', 'orden' => 3],
            ['nombre' => 'Gastronomía', 'slug' => 'gastronomia', 'descripcion' => 'Catas de café, tours culinarios y coctelería de autor.', 'orden' => 4],
            ['nombre' => 'Bienestar y Relax', 'slug' => 'bienestar-y-relax', 'descripcion' => 'Spas, retiros holísticos y glamping en entornos tranquilos.', 'orden' => 5],
            ['nombre' => 'Playa y Sol', 'slug' => 'playa-y-sol', 'descripcion' => 'Paseos en velero, snorkeling y descanso caribeño sin prejuicios.', 'orden' => 6],
        ];

        foreach ($categorias as $cat) {
            DB::table('categorias')->updateOrInsert(
                ['slug' => $cat['slug']],
                array_merge($cat, $audit, ['estado' => true])
            );
        }

        // 2. Destinos
        $destinos = [
            [
                'nombre' => 'Bogotá D.C.',
                'slug' => 'bogota',
                'pais' => 'Colombia',
                'departamento' => 'Cundinamarca',
                'ciudad' => 'Bogotá',
                'descripcion' => 'La capital cosmopolita con la mayor oferta cultural y vida nocturna diversa de América Latina.',
                'imagen' => 'brand/banner3.png',
                'latitud' => 4.6097100,
                'longitud' => -74.0817500,
                'destacado' => true,
            ],
            [
                'nombre' => 'Medellín',
                'slug' => 'medellin',
                'pais' => 'Colombia',
                'departamento' => 'Antioquia',
                'ciudad' => 'Medellín',
                'descripcion' => 'La ciudad de la eterna primavera, famosa por su innovación, arte urbano en la Comuna 13 y ambiente queer acogedor.',
                'imagen' => 'brand/banner3.png',
                'latitud' => 6.2442000,
                'longitud' => -75.5812100,
                'destacado' => true,
            ],
            [
                'nombre' => 'Cartagena de Indias',
                'slug' => 'cartagena',
                'pais' => 'Colombia',
                'departamento' => 'Bolívar',
                'ciudad' => 'Cartagena',
                'descripcion' => 'Historia colonial, murallas junto al mar, atardeceres dorados y paseos exclusivos en islas.',
                'imagen' => 'brand/banner3.png',
                'latitud' => 10.3910500,
                'longitud' => -75.4794300,
                'destacado' => true,
            ],
            [
                'nombre' => 'Cali',
                'slug' => 'cali',
                'pais' => 'Colombia',
                'departamento' => 'Valle del Cauca',
                'ciudad' => 'Cali',
                'descripcion' => 'Capital mundial de la salsa, con una escena cultural diversa vibrante y gastronomía pacífica inigualable.',
                'imagen' => 'brand/banner3.png',
                'latitud' => 3.4516500,
                'longitud' => -76.5319800,
                'destacado' => true,
            ],
            [
                'nombre' => 'Santa Marta',
                'slug' => 'santa-marta',
                'pais' => 'Colombia',
                'departamento' => 'Magdalena',
                'ciudad' => 'Santa Marta',
                'descripcion' => 'La magia de tener la Sierra Nevada y el Parque Tayrona fusionados con playas paradisíacas.',
                'imagen' => 'brand/banner3.png',
                'latitud' => 11.2407900,
                'longitud' => -74.1990400,
                'destacado' => true,
            ],
            [
                'nombre' => 'Valle del Cocora - Salento',
                'slug' => 'valle-del-cocora-salento',
                'pais' => 'Colombia',
                'departamento' => 'Quindío',
                'ciudad' => 'Salento',
                'descripcion' => 'Cuna de las palmas de cera más altas del mundo y aromas inconfundibles de café suave colombiano.',
                'imagen' => 'brand/banner3.png',
                'latitud' => 4.6375000,
                'longitud' => -75.5702800,
                'destacado' => true,
            ],
            [
                'nombre' => 'San Andrés Isla',
                'slug' => 'san-andres-isla',
                'pais' => 'Colombia',
                'departamento' => 'San Andrés y Providencia',
                'ciudad' => 'San Andrés',
                'descripcion' => 'El mar de los siete colores, corales multicolores y vibra isleña caribeña llena de libertad.',
                'imagen' => 'brand/banner3.png',
                'latitud' => 12.5847200,
                'longitud' => -81.7005600,
                'destacado' => true,
            ],
        ];

        foreach ($destinos as $dest) {
            DB::table('destinos')->updateOrInsert(
                ['slug' => $dest['slug']],
                array_merge($dest, $audit, ['estado' => true])
            );
        }

        // 3. Proveedor Turístico
        $proveedorExistente = DB::table('proveedores_turisticos')->first();
        if ($proveedorExistente) {
            $proveedorId = $proveedorExistente->id;
        } else {
            $proveedorId = DB::table('proveedores_turisticos')->insertGetId(array_merge([
                'nombre_comercial' => 'Travel City Experiences',
                'razon_social' => 'Travel City Colombia SAS',
                'nit' => '901555123-1',
                'descripcion' => 'Operador turístico especializado en experiencias LGTBIQ+ certificadas por toda Colombia.',
                'telefono' => '+57 300 123 4567',
                'correo' => 'contacto@travelcity.co',
                'direccion' => 'Calle 72 # 10-07, Bogotá',
                'sitio_web' => 'https://travelcity.co',
                'rnt' => 'RNT-98421',
                'estado_verificacion' => 'aprobado',
                'verificado_en' => $ahora,
                'verificado_por' => 1,
                'estado' => true,
            ], $audit));
        }

        // Mapear Ids
        $idBogota = DB::table('destinos')->where('slug', 'bogota')->value('id');
        $idMedellin = DB::table('destinos')->where('slug', 'medellin')->value('id');
        $idCartagena = DB::table('destinos')->where('slug', 'cartagena')->value('id');
        $idCali = DB::table('destinos')->where('slug', 'cali')->value('id');
        $idSantaMarta = DB::table('destinos')->where('slug', 'santa-marta')->value('id');
        $idSalento = DB::table('destinos')->where('slug', 'valle-del-cocora-salento')->value('id');
        $idSanAndres = DB::table('destinos')->where('slug', 'san-andres-isla')->value('id');

        $catVidaNocturna = DB::table('categorias')->where('slug', 'vida-nocturna')->value('id');
        $catCultura = DB::table('categorias')->where('slug', 'cultura-y-arte')->value('id');
        $catNaturaleza = DB::table('categorias')->where('slug', 'naturaleza-y-aventura')->value('id');
        $catGastronomia = DB::table('categorias')->where('slug', 'gastronomia')->value('id');
        $catBienestar = DB::table('categorias')->where('slug', 'bienestar-y-relax')->value('id');
        $catPlaya = DB::table('categorias')->where('slug', 'playa-y-sol')->value('id');

        // 4. Experiencias
        $experiencias = [
            [
                'nombre' => 'Nightlife & Drag Show en Theatron Chapinero',
                'slug' => 'nightlife-drag-show-theatron-chapinero',
                'destino_id' => $idBogota,
                'categoria_id' => $catVidaNocturna,
                'descripcion' => 'Descubre el club LGTBIQ+ más grande de América Latina con acceso VIP, guía local, cóctel de bienvenida y show drag estelar.',
                'precio_desde' => 120000.00,
                'duracion' => '5 horas',
                'punto_encuentro' => 'Parque de Lourdes, Chapinero',
                'direccion' => 'Calle 58 # 10-32, Chapinero, Bogotá',
                'latitud' => 4.6465000,
                'longitud' => -74.0628000,
            ],
            [
                'nombre' => 'Comuna 13 Art & Queer History Tour',
                'slug' => 'comuna-13-art-queer-history-tour',
                'destino_id' => $idMedellin,
                'categoria_id' => $catCultura,
                'descripcion' => 'Recorrido por los murales, escaleras eléctricas y la memoria de resiliencia comunitaria con artistas locales de la diversidad.',
                'precio_desde' => 95000.00,
                'duracion' => '3.5 horas',
                'punto_encuentro' => 'Estación Metro San Javier',
                'direccion' => 'Cra. 109 # 35-1, Comuna 13, Medellín',
                'latitud' => 6.2518000,
                'longitud' => -75.6155000,
            ],
            [
                'nombre' => 'Sunset Cruise & Catamarán en Islas del Rosario',
                'slug' => 'sunset-cruise-catamaran-islas-del-rosario',
                'destino_id' => $idCartagena,
                'categoria_id' => $catPlaya,
                'descripcion' => 'Navegación exclusiva al atardecer por la bahía y el archipiélago con barra libre de cócteles tropicales, DJ en vivo y ambiente libre.',
                'precio_desde' => 240000.00,
                'duracion' => '4 horas',
                'punto_encuentro' => 'Muelle de la Bodeguita, Puerta 3',
                'direccion' => 'Avenida Blas de Lezo, Centro Histórico, Cartagena',
                'latitud' => 10.4202000,
                'longitud' => -75.5528000,
            ],
            [
                'nombre' => 'Clase de Salsa y Noche Bohemio-Diversa en San Antonio',
                'slug' => 'clase-salsa-noche-bohemia-san-antonio',
                'destino_id' => $idCali,
                'categoria_id' => $catVidaNocturna,
                'descripcion' => 'Aprende los pasos clásicos de salsa caleña sin etiquetas de rol en academia amigable y remata en los mejores bares de San Antonio.',
                'precio_desde' => 85000.00,
                'duracion' => '3 horas',
                'punto_encuentro' => 'Plazoleta San Antonio',
                'direccion' => 'Carrera 10 # 1-20, San Antonio, Cali',
                'latitud' => 3.4475000,
                'longitud' => -76.5398000,
            ],
            [
                'nombre' => 'Ecoturismo y Playas Secretas del Parque Tayrona',
                'slug' => 'ecoturismo-playas-secretas-parque-tayrona',
                'destino_id' => $idSantaMarta,
                'categoria_id' => $catNaturaleza,
                'descripcion' => 'Caminata guiada entre selva tropical y arrecifes de ensueño como Cabo San Juan y La Piscina, respetando el entorno ancestral.',
                'precio_desde' => 190000.00,
                'duracion' => '8 horas',
                'punto_encuentro' => 'Entrada El Zaíno, Parque Tayrona',
                'direccion' => 'Km 34 Vía Riohacha, Santa Marta',
                'latitud' => 11.3130000,
                'longitud' => -73.9360000,
            ],
            [
                'nombre' => 'Ruta del Café Especial y Cabalgata en Valle del Cocora',
                'slug' => 'ruta-cafe-especial-valle-cocora',
                'destino_id' => $idSalento,
                'categoria_id' => $catGastronomia,
                'descripcion' => 'Inmersión en hacienda cafetera tradicional, degustación de cafés de origen y vistas panorámicas de las palmas de cera.',
                'precio_desde' => 150000.00,
                'duracion' => '5 horas',
                'punto_encuentro' => 'Plaza Principal de Salento (Punto Willyz)',
                'direccion' => 'Valle del Cocora Km 11, Salento',
                'latitud' => 4.6380000,
                'longitud' => -75.4880000,
            ],
            [
                'nombre' => 'Snorkeling en Barrera de Coral y Paseo en Cayo Bolívar',
                'slug' => 'snorkeling-barrera-coral-cayo-bolivar',
                'destino_id' => $idSanAndres,
                'categoria_id' => $catPlaya,
                'descripcion' => 'Descubre mantarrayas, tortugas marinas y corales protegidos en aguas cristalinas con instructores bilingües y equipo certificado.',
                'precio_desde' => 210000.00,
                'duracion' => '4.5 horas',
                'punto_encuentro' => 'Marina Toninos, Spratt Bight',
                'direccion' => 'Av. Colombia # 2-30, San Andrés',
                'latitud' => 12.5830000,
                'longitud' => -81.6960000,
            ],
            [
                'nombre' => 'Tour de Coctelería de Autor y Rooftops en Zona T',
                'slug' => 'tour-cocteleria-autor-rooftops-zona-t',
                'destino_id' => $idBogota,
                'categoria_id' => $catGastronomia,
                'descripcion' => 'Ruta por tres de los mejores bares y terrazas del norte de Bogotá degustando coctelería inspirada en frutas exóticas colombianas.',
                'precio_desde' => 140000.00,
                'duracion' => '3.5 horas',
                'punto_encuentro' => 'Centro Comercial Andino (Entrada Calle 82)',
                'direccion' => 'Calle 82 # 12-21, Zona Rosa, Bogotá',
                'latitud' => 4.6669000,
                'longitud' => -74.0538000,
            ],
            [
                'nombre' => 'Glamping Ecológico y Tour en Kayak por el Embalse de Guatapé',
                'slug' => 'glamping-ecologico-kayak-embalse-guatape',
                'destino_id' => $idMedellin,
                'categoria_id' => $catBienestar,
                'descripcion' => 'Día de descanso, kayak sereno en el lago, ascenso a la Piedra del Peñol y gastronomía paisa en espacio 100% amigable.',
                'precio_desde' => 280000.00,
                'duracion' => '1 día',
                'punto_encuentro' => 'Piedra del Peñol, Parqueadero Principal',
                'direccion' => 'Vereda La Piedra, Guatapé, Antioquia',
                'latitud' => 6.2325000,
                'longitud' => -75.1790000,
            ],
        ];

        foreach ($experiencias as $exp) {
            $catId = $exp['categoria_id'];
            unset($exp['categoria_id']);

            DB::table('experiencias')->updateOrInsert(
                ['slug' => $exp['slug']],
                array_merge($exp, $audit, [
                    'proveedor_id' => $proveedorId,
                    'estado' => 'publicada',
                    'destacada' => true,
                    'verificada' => true,
                    'idioma' => 'Español / Inglés',
                    'incluye' => 'Guía bilingüe experto, Seguro médico de asistencia, Entradas y degustaciones según itinerario',
                    'no_incluye' => 'Gastos personales no especificados, Propinas voluntarias',
                    'capacidad_maxima' => 20,
                ])
            );

            $id = DB::table('experiencias')->where('slug', $exp['slug'])->value('id');
            if ($id && $catId) {
                DB::table('experiencia_categoria')->updateOrInsert(
                    ['experiencia_id' => $id, 'categoria_id' => $catId]
                );
            }
        }
    }
}
