<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Evento;
use App\Inscripcion;
use App\Persona;
use App\DetallePagos;
use App\Seccion;
use App\Playera;
use App\Estudiante;
use App\DetallePlayeras;
use Mail;
use App\Mail\PreinscripcionCitein;
use App\Mail\PagoRealizadoCitein;
use App\Mail\BienvenidoInscripcionCompletaCitein;
use App\Usuarios;
use DB;

use Datatables;

class EstudiantesController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
     public function index()
     {
       $secciones = Seccion::get();
       $playeras = Playera::get();
       $pagos = DB::table('view_abonos')->get();
       $evento = Evento::whereYear('created_at', '=', date('Y'))->first();

       $informaciones = DB::table('view_retornaralumnos')->get();

       return view('inscripciones.alumnos.home')->with('secciones',$secciones)
       ->with('playeras',$playeras)->with('informaciones', $informaciones)
       ->with('pagos', $pagos)
       ->with('evento', $evento);
     }

     public function dataEstudiantes()
     {
       $estudiantes = null;

       if (auth()->user()->permiso == 'Administrador')
       {
         $estudiantes = DB::table('view_retornaralumnos');
       }

       if (auth()->user()->permiso == 'Encargado de Seccion') {
         $estudiantes = DB::table('view_retornaralumnos')->where('nombreseccion', auth()->user()->seccion);
       }

       return datatables()->of($estudiantes)
       ->addColumn('Actividades', function($estudiantes){
         return '
         <form action='.url("listado_actividades/inscripcion", $estudiantes->idinscripcion).' method="post">
         <input type="hidden" name="_token" value='.csrf_token().'></input>
         <div class="table-data-feature">
             <button class="item" data-toggle="tooltip" data-placement="top" title="Actividades" type="submit">
               <i class="zmdi zmdi-view-toc"></i>
             </button>
         </div>
         </form>
         ';
       })

       ->addColumn('Acciones', function($estudiantes){
         if (auth()->user()->permiso == 'Administrador')
         {
           return '
           <div class="table-data-feature">
              <a class="item" data-placement="top" title="Control de Pagos" href="#PagosModal-'.$estudiantes->idinscripcion.'" data-toggle="modal" >
                <i class="zmdi zmdi-money"></i>
              </a>
              <a class="item" data-placement="top" title="Editar"  href="#EditarModal-'.$estudiantes->idpersona.'" data-toggle="modal" data-toggle="tooltip">
                <i class="zmdi zmdi-edit"></i>
              </a>
              <a class="item" data-toggle="tooltip" data-placement="top" title="Eliminar"  href='.url("estudiantes/destroy",[$estudiantes->idpersona,$estudiantes->idinscripcion] ).' type="submit">
                  <i class="zmdi zmdi-delete"></i>
              </a>
           </div>
                  ';
         }else {
           return '
           <div class="table-data-feature">
              <a class="item" data-placement="top" title="Control de Pagos" href="#PagosModal-'.$estudiantes->idinscripcion.'" data-toggle="modal" >
                <i class="zmdi zmdi-money"></i>
              </a>
              <a class="item" data-placement="top" title="Editar"  href="#EditarModal-'.$estudiantes->idpersona.'" data-toggle="modal" data-toggle="tooltip">
                <i class="zmdi zmdi-edit"></i>
              </a>
           </div>
                  ';
         }

       })
       ->rawColumns(['Actividades', 'Acciones'])
       ->toJson();

     }

     public function inscribir(Request $request)
     {
       DB::beginTransaction();
       try {
           $now = new \DateTime();

           $year = $now->format('Y');
             //codigo sql
           $evento = Evento::whereYear('created_at', '=', date('Y'))->first();
           $idEvento = $evento->id; //id del evento en curso
           $costoCena = $evento->costoCenaExtra;

           //id del evento en curso
           $persona = new Persona;
           $persona->id_tipoPersona = 1;//1 porque es estudiante
           $persona->nombre = $request->input('nombre');
           $persona->apellido = $request->input('apellido');
           $persona->genero = $request->input('radioGenero');
           $persona->edad = $request->input('edad');
           $persona->correo = $request->input('correo');
           $persona->save();

           //id de las persona que recien se inserto
           $idPersona = $persona->id;

           $estudiante = new Estudiante;
           $estudiante->id_personas = $idPersona;
           $estudiante->id_secciones = $request->input('selectSeccion');
           $estudiante->carne = $request->input('carne');
           $estudiante->save();

           $PagoTotal = ($request->input('cantidadCenasExtras')*$costoCena)+$evento->costoInscripcion;

           $inscripcion = new Inscripcion;
           $inscripcion->id_personas = $idPersona;
           $inscripcion->id_eventos = $idEvento;
           $inscripcion->cantidadCenasExtras = $request->input('cantidadCenasExtras');
           $inscripcion->totalAPagar = $PagoTotal;
           $inscripcion->save();

           //id de la ultima inscripcion
           $idInscripcion = $inscripcion->id;

           $abono = new DetallePagos;
           $abono->id_inscripciones = $idInscripcion;
           $abono->abono = $request->input('pago');
           $abono->save();

           $detallePlayeras = new DetallePlayeras;
           $detallePlayeras->id_inscripciones= $idInscripcion;
           $detallePlayeras->id_playeras= $request->input('selectPlayera');
           $detallePlayeras->save();

           //envio de correo electronico
           $pagos = DB::table('view_abonos')
           ->where('id_inscripciones', $idInscripcion)->first();

           $inscripcion = Inscripcion::where('id', $pagos->id_inscripciones)->first();
           $persona = Persona::where('id', $inscripcion->id_personas)->first();
           $estudiante = Estudiante::where('id_personas', $persona->id)->first();

           if ($pagos->restante == 0) {
             //generar su codigo QR
             QRCodeController::guardarImagen($idInscripcion, $estudiante->carne);

             $path = "qrcodes"."/".$estudiante->carne.".png";

             $datosPagos = (object)array(
                     'nombres' => $pagos->nombre.' '.$pagos->apellido,
                     'carne' => $estudiante->carne,
                     'codigoqr' => $path,
                     'year' => $year
             );

             Mail::to($pagos->correo)->send(new BienvenidoInscripcionCompletaCitein($datosPagos));
           }else {
             //envio de correo electronico
             $datosPreinscripcion = DB::table('view_retornaralumnos')
             ->where('idinscripcion', $idInscripcion)->first();

             $datosPreinscripcion = (object)array(
                     'carne' => $datosPreinscripcion->carne,
                     'nombres' => $datosPreinscripcion->nombre.' '.$datosPreinscripcion->apellido,
                     'seccion' => $datosPreinscripcion->nombreseccion,
                     'abono' => $request->input('pago'),
                     'year' => $year
                 );
             $correo_enviar = $request->input('correo');

             Mail::to($correo_enviar)->send(new PreinscripcionCitein($datosPreinscripcion));
           }

         //siempre tiene que ir
         DB::commit();
         $success = true;

         } catch (\Exception $e) {
         DB::rollback();
         $success = false;
         }

       //condiciones
       if ($success) {
         return redirect('estudiantes')->with('info','Inscripción realizada con éxito.');
       }
       else {
         return redirect('estudiantes')->with('error', 'Ocurrió un error al realizar la inscripción.');
       }

     }

     public function update(Request $request, $idPersona, $idInscripcion)
     {
       DB::beginTransaction();
       try {
           $evento = Evento::whereYear('created_at', '=', date('Y'))->first();
           $costoCena = $evento->costoCenaExtra;


             //codigo sql
           $estudiante = array(
                'carne' => $request->input('carne'),
                'id_secciones' =>$request->input('selectSeccion')
            );
            Estudiante::where('id_personas', $idPersona)->update($estudiante);

            $persona = array(
                 'nombre' => $request->input('nombre'),
                 'apellido' => $request->input('apellido'),
                 'genero' => $request->input('radioGenero'),
                 'edad' => $request->input('edad'),
                 'correo' => $request->input('correo')
            );
            Persona::where('id', $idPersona)->update($persona);

            $PagoTotal = ($request->input('cantidadCenasExtras')*$costoCena)+$evento->costoInscripcion;

            $inscripcion = array(
                'cantidadCenasExtras' => $request->input('cantidadCenasExtras'),
                'totalAPagar' => $PagoTotal
            );
            Inscripcion::where('id', $idInscripcion)->update($inscripcion);

            $detallePlayeras = array(
                  'id_playeras' => $request->input('selectPlayera')
            );
            DetallePlayeras::where('id_inscripciones', $idInscripcion)->update($detallePlayeras);


           //siempre tiene que ir
           DB::commit();
           $success = true;

           } catch (\Exception $e) {
           DB::rollback();
           $success = false;
           }

           //condiciones
           if ($success) {
             return redirect('estudiantes')->with('info','Actualizado con éxito.');
           }
           else {
             return redirect('estudiantes')->with('error', 'Ocurrió un error al actualzar la inscripción.');
           }
     }

      public function pagos(Request $request, $idInscripcion)
      {
        //inicio de la transaccion
        DB::beginTransaction();
        try {
            $now = new \DateTime();
            $year = $now->format('Y');

            $pagos = new DetallePagos;
            $pagos->id_inscripciones = $idInscripcion;
            $pagos->abono = $request->input('pagos');
            $pagos->save();

            //envio de correo electronico
            $pagos = DB::table('view_abonos')
            ->where('id_inscripciones', $idInscripcion)->first();

            $inscripcion = Inscripcion::where('id', $pagos->id_inscripciones)->first();
            $persona = Persona::where('id', $inscripcion->id_personas)->first();
            $estudiante = Estudiante::where('id_personas', $persona->id)->first();

            if ($pagos->restante == 0) {
              //generar su codigo QR
              QRCodeController::guardarImagen($idInscripcion, $estudiante->carne);

              $path = "qrcodes"."/".$estudiante->carne.".png";

              $datosPagos = (object)array(
                      'nombres' => $pagos->nombre.' '.$pagos->apellido,
                      'carne' => $estudiante->carne,
                      'codigoqr' => $path,
                      'year' => $year
              );

              Mail::to($pagos->correo)->send(new BienvenidoInscripcionCompletaCitein($datosPagos));
            }else {
              $datosPagos = (object)array(
                      'abono' => $request->input('pagos'),
                      'restante' => $pagos->restante,
                      'year' => $year
                  );

              Mail::to($pagos->correo)->send(new PagoRealizadoCitein($datosPagos));
            }



            //siempre tiene que ir
            DB::commit();

            //cambio de variable a true
            $success = true;

            } catch (\Exception $e) {
              //cambio de variable a false

            DB::rollback();
            $success = false;
          }

        //condiciones
        if ($success) {
          return redirect('estudiantes')->with('info','Pago realizado con éxito.');
          }
        else {
          return redirect('estudiantes')->with('error', 'Ocurrió un error al realizar el pago.');
          }

      }

      public function destroy($idPersona, $idInscripcion)
      {
        DB::beginTransaction();
        try {
              //codigo sql
              //dos linesa para borrar
            DetallePagos::destroy($idInscripcion);
            DetallePlayeras::where('id_inscripciones', $idInscripcion)->delete();
            //aqui termina
            Inscripcion::destroy($idInscripcion);
            Estudiante::where('id_personas', $idPersona)->delete();
            Persona::destroy($idPersona);

            //siempre tiene que ir
            DB::commit();
            $success = true;

            } catch (\Exception $e) {
            DB::rollback();
            $success = false;
            }

            //condiciones
            if ($success) {
            return redirect('estudiantes')->with('info', 'Eliminado con éxito.');
            }
            else {
              return redirect('estudiantes')->with('error', 'Ocurrió un error al eliminar al estudiante.');
            }
      }


}
