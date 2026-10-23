<?php
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/class/ui.php");
require_once(__DIR__ . "/lang/i18n.php");
$conexion=conectarse();
if (!isset($_SESSION["rol"])) {
    header("Location: break.php");
    exit();
}
require_once(__DIR__ . "/class/permisos.php");
requerir('agenda.notificacion');
?>
<!doctype html>
<html lang="<?php echo current_lang(); ?>">
<head>
    <meta charset="utf-8">
    <!-- Favicon de la app -->
    <link rel="icon" type="image/svg+xml" href="images/favicon.svg">
    <link rel="alternate icon" type="image/png" href="images/favicon.png">
    <link rel="apple-touch-icon" href="images/favicon.png">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="Content-Language" content="en">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title><?php te('notif.pageTitle'); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, shrink-to-fit=no" />
    <meta name="description" content="Calendars are used in a lot of apps. We thought to include one for React.">
    <meta name="msapplication-tap-highlight" content="no">
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css' rel='stylesheet'>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css' rel='stylesheet'>
      <!-- Bootstrap CSS -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

<!-- Bootstrap JS (necesario para que funcionen los modales) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/jquery.min.js"></script>
    <link href='./fullcalendar/main.css' rel='stylesheet' />
    <script src='./fullcalendar/main.js'></script>
    <script src="./js/calendar.js?2"></script>
    <link href="./main.css" rel="stylesheet"></head>
<body>
<div class="app-container app-theme-white body-tabs-shadow fixed-sidebar fixed-header">
        
        
        <div class="app-header header-shadow">
            <div class="app-header__logo">
                                <div class="logo-src"></div>
                                <div class="header__pane ml-auto">
                                    <div>
                                        <button type="button" class="hamburger close-sidebar-btn hamburger--elastic" data-class="closed-sidebar">
                                            <span class="hamburger-box">
                                                <span class="hamburger-inner"></span>
                                            </span>
                                        </button>
                                    </div>
                                </div>
            </div>
            <div class="app-header__mobile-menu">
                        <div>
                            <button type="button" class="hamburger hamburger--elastic mobile-toggle-nav">
                                <span class="hamburger-box">
                                    <span class="hamburger-inner"></span>
                                </span>
                            </button>
                        </div>
            </div>
            <div class="app-header__menu">
                <span>
                    <button type="button" class="btn-icon btn-icon-only btn btn-primary btn-sm mobile-toggle-header-nav">
                        <span class="btn-icon-wrapper">
                            <i class="fa fa-ellipsis-v fa-w-6"></i>
                        </span>
                    </button>
                </span>
            </div>    
            <div class="app-header__content">
                        <div class="app-header-left">
                         
                            <ul class="header-menu nav">
                                <li class="nav-item">
                                    <a href="javascript:void(0);" class="nav-link">
                                        <i class="nav-link-icon fa fa-database"> </i>
                                        <?php te('hdr.statistics'); ?>
                                    </a>
                                </li>
                                <li class="dropdown nav-item">
                                    <a href="javascript:void(0);" class="nav-link">
                                        <i class="nav-link-icon fa fa-cog"></i>
                                        <?php te('hdr.settings'); ?>
                                    </a>
                                </li>
                            </ul>        </div>
                        <div class="app-header-right">
                            <div class="header-btn-lg pr-0">
                                <div class="widget-content p-0">
                                    <div class="widget-content-wrapper">
                                        <div class="widget-content-left">
                                            <div class="btn-group">
                                                <a data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" class="p-0 btn">
                                                    <img width="42" class="rounded-circle" >
                                                    <i class="fa fa-angle-down ml-2 opacity-8"></i>
                                                </a>
                                                <div tabindex="-1" role="menu" aria-hidden="true" class="dropdown-menu dropdown-menu-right">
                                                    <a href="perfil.php" tabindex="0" class="dropdown-item"><?php te('hdr.userProfile'); ?></a>
                                                    <button type="button" tabindex="0" class="dropdown-item"><?php te('hdr.settings'); ?></button>
                                                    <div tabindex="-1" class="dropdown-divider"></div>
                                                    <a type="button" tabindex="0" href="salir.php" class="dropdown-item"><?php te('menu.logout'); ?></a>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="widget-content-left  ml-3 header-user-info">
                                            <div class="widget-heading">
                                                Admin
                                            </div>
                                            <div class="widget-subheading">
                                                Administrator
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>        
                        </div>
            </div>
        </div>
        <div class="ui-theme-settings" style="visibility:hidden">
                    <button type="button" id="TooltipDemo" class="btn-open-options btn btn-warning">
                        <i class="fa fa-cog fa-w-16 fa-spin fa-2x"></i>
                    </button>
                    <div class="theme-settings__inner">
                        <div class="scrollbar-container">
                            <div class="theme-settings__options-wrapper">
                               
                                
                                <h3 class="themeoptions-heading">
                                    <div>
                                        Cabecera
                                    </div>
                                    <button type="button" class="btn-pill btn-shadow btn-wide ml-auto btn btn-focus btn-sm switch-header-cs-class" data-class="">
                                        Restablecer
                                    </button>
                                </h3>
                                
                                <h3 class="themeoptions-heading">
                                    <div>Menu</div>
                                    <button type="button" class="btn-pill btn-shadow btn-wide ml-auto btn btn-focus btn-sm switch-sidebar-cs-class" data-class="">
                                        Restablecer
                                    </button>
                                </h3>                
                                
                            </div>
                        </div>
                    </div>
        </div> 

        <div class="app-main">
            <div class="app-sidebar sidebar-shadow">
            <!-- ======= MENU========================  -->
            <?php include("./menu/menu_adm.php"); ?>          
                
                </div>    
                <div class="app-main__outer">
                    <div class="app-main__inner">
                        <div class="app-page-title">
                            <div class="page-title-wrapper">
                                <div class="page-title-heading">
                                    <div class="page-title-icon">
                                        <i class="pe-7s-mail icon-gradient bg-warm-flame">
                                        </i>
                                    </div>
                                    <div>Enviar notificación
                                        <div class="page-title-subheading">Enviar recordatorio al paciente.
                                        </div>
                                    </div>
                                </div>
                                <div class="page-title-actions">
                                  <!--   <button type="button" data-toggle="tooltip" title="Example Tooltip" data-placement="bottom" class="btn-shadow mr-3 btn btn-dark">
                                        <i class="fa fa-star"></i>
                                    </button>
                                    <div class="d-inline-block dropdown">
                                        <button type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" class="btn-shadow dropdown-toggle btn btn-info">
                                            <span class="btn-icon-wrapper pr-2 opacity-7">
                                                <i class="fa fa-business-time fa-w-20"></i>
                                            </span>
                                            Buttons
                                        </button>
                                        <div tabindex="-1" role="menu" aria-hidden="true" class="dropdown-menu dropdown-menu-right">
                                            <ul class="nav flex-column">
                                                <li class="nav-item">
                                                    <a href="javascript:void(0);" class="nav-link">
                                                        <i class="nav-link-icon lnr-inbox"></i>
                                                        <span>
                                                            Inbox
                                                        </span>
                                                        <div class="ml-auto badge badge-pill badge-secondary">86</div>
                                                    </a>
                                                </li>
                                                <li class="nav-item">
                                                    <a href="javascript:void(0);" class="nav-link">
                                                        <i class="nav-link-icon lnr-book"></i>
                                                        <span>
                                                            Book
                                                        </span>
                                                        <div class="ml-auto badge badge-pill badge-danger">5</div>
                                                    </a>
                                                </li>
                                                <li class="nav-item">
                                                    <a href="javascript:void(0);" class="nav-link">
                                                        <i class="nav-link-icon lnr-picture"></i>
                                                        <span>
                                                            Picture
                                                        </span>
                                                    </a>
                                                </li>
                                                <li class="nav-item">
                                                    <a disabled href="javascript:void(0);" class="nav-link disabled">
                                                        <i class="nav-link-icon lnr-file-empty"></i>
                                                        <span>
                                                            File Disabled
                                                        </span>
                                                    </a>
                                                </li>
                                            </ul>
                                        </div>
                                    </div> -->
                                    
                                </div>    
                            </div>
                        </div>            
                        <ul class="body-tabs body-tabs-layout tabs-animated body-tabs-animated nav">
                            <li class="nav-item">
                                <a role="tab" class="nav-link active" id="tab-0" data-toggle="tab" href="#tab-content-0">
                                    <span><?php te('pcreate.tab.register'); ?></span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a role="tab" class="nav-link" id="tab-1" data-toggle="tab" href="#tab-content-1">
                                    <span><?php te('pcreate.tab.list'); ?></span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a role="tab" class="nav-link" id="tab-2" data-toggle="tab" href="#tab-content-2">
                                    <!-- <span>Background Events</span> -->
                                </a>
                            </li>
                        </ul>
                        <div class="tab-content">
                            <div class="tab-pane tabs-animation fade show active" id="tab-content-0" role="tabpanel">
                                <div class="main-card mb-3 card">
                                    <div class="card-body">
                                        <!-- <div id='calendar1'></div> -->
                                        <div class="main-card mb-3 card">
                                            <div class="card-body">
                                           <!-- <h5 class="card-title"><?php te('notif.info'); ?></h5>
                                            <form method="POST" action="enviar_correo.php">
                                                <input type="email" name="email" placeholder="Correo destino" required>
                                                <textarea name="mensaje" placeholder="Mensaje"></textarea>
                                                <button type="submit">Enviar</button>
                                            </form> -->


                                                <h5 class="card-title"><?php te('notif.info'); ?></h5>
                                                <form class="needs-validation" novalidate method="post" action="class/Insert_cita.php">
                                                    <div class="form-row">
                                                        <div class="col-md-4 mb-3">
                                                            <label for="validationCustom01"><?php te('notif.subject'); ?></label>
                                                            <input type="text" class="form-control" id="asunto" name="asunto" placeholder="asunto" value="Cita Programada" required>
                                                            <div class="valid-feedback">
                                                                Looks good!
                                                            </div>
                                                        </div>
                                                        <div class="col-md-4 mb-3">
                                                            <label for="validationCustom02"><?php te('notif.recipient'); ?></label>
                                                            <input type="text" class="form-control" id="email" name="email" placeholder="correo@dominio.com" value="jvaras@overclocking.com.ec" required>
                                                            <div class="valid-feedback">
                                                                Looks good!
                                                            </div>
                                                        </div>
                                                       <div class="col-md-4 mb-3">
                                                           <label for="validationCustom04"><?php te('notif.message'); ?></label>
                                                           <input type="text" class="form-control" id="mensaje" name="mensaje" placeholder="mensaje"  value="Ud, tinee una cita programada para hoy" required>
                                                           <div class="invalid-feedback">
                                                               Please provide a valid Phone.
                                                           </div>
                                                       </div>
                                                    </div>
                                                    <div class="form-row">
                                                        <div class="col-md-4 mb-3">
                                                              <!-- <label for="validationCustomUsername">Especialidad</label>
                                                            <div class="input-group">
                                                                <div class="input-group-prepend">
                                                                    <span class="input-group-text" id="inputGroupPrepend"><i class="pe-7s-user"> </i></span>
                                                                </div>
                                                                <input type="text" class="form-control" id="validationCustomUsername" placeholder="Especialidad" aria-describedby="inputGroupPrepend" required>
                                                                <div class="invalid-feedback">
                                                                    Please choose a Especialidad.
                                                                </div>
                                                            </div> -->
                                                        </div>
                                                          <!-- <div class="col-md-4 mb-3">
                                                            <label for="validationCustom03"><?php te('notif.email'); ?></label>
                                                            <input type="email" class="form-control" id="validationCustom03" placeholder="E-mail" required>
                                                            <div class="invalid-feedback">
                                                                Please provide a valid Password.
                                                            </div>
                                                        </div>
                                                        -->


                                                        <div class="col-md-4 mb-3">
                                                            <!-- <label for="validationCustom05">Rol</label>
                                                            <div class="position-relative form-group">
                                                                <select name="select" id="validationCustom05" class="form-control" required>
                                                                <option>Default Select</option>
                                                                <option>Sistema</option>
                                                                <option>Doctor</option>
                                                                <option>Usuario</option> 
                                                            </select>
                                                        </div> -->
                                                         <div class="invalid-feedback">
                                                                Please provide a valid rol.
                                                            </div>
                                                        </div>
                                                    </div>
                                                      <!-- 
                                                    <div class="form-group">
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" value="" id="invalidCheck" required>
                                                            <label class="form-check-label" for="invalidCheck">
                                                                Agree to terms and conditions
                                                            </label>
                                                            <div class="invalid-feedback">
                                                                You must agree before submitting.
                                                            </div>
                                                        </div>
                                                    </div> -->
                                                    <button class="btn btn-primary" type="submit"><?php te('common.save'); ?></button>
                                                </form>
                                        
                                                <script>
                                                    // Example starter JavaScript for disabling form submissions if there are invalid fields
                                                    (function() {
                                                        'use strict';
                                                        window.addEventListener('load', function() {
                                                            // Fetch all the forms we want to apply custom Bootstrap validation styles to
                                                            var forms = document.getElementsByClassName('needs-validation');
                                                            // Loop over them and prevent submission
                                                            var validation = Array.prototype.filter.call(forms, function(form) {
                                                                form.addEventListener('submit', function(event) {
                                                                    if (form.checkValidity() === false) {
                                                                        event.preventDefault();
                                                                        event.stopPropagation();
                                                                    }
                                                                    form.classList.add('was-validated');
                                                                }, false);
                                                            });
                                                        }, false);
                                                    })();
                                                </script>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="tab-pane tabs-animation fade" id="tab-content-1" role="tabpanel">
                                <div class="main-card mb-3 card">
                                    <div class="card-body">
                                        <!-- <div id='calendar-list'></div> -->
                                        <div class="main-card mb-3 card">
                                    <div class="card-body">
                                        <!-- <div id='calendar1'></div> -->
                                        <table class="table align-middle mb-0 bg-white">
                                          <thead class="bg-light">
                                            <tr>
                                              <th><?php te('ucreate.th.name'); ?></th>
                                              <th><?php te('doc.th.specialty'); ?></th>
                                              <th><?php te('doc.th.status'); ?></th>
                                              <!-- <th>Position</th> -->
                                              <th><?php te('pcreate.th.actions'); ?></th>
                                            </tr>
                                          </thead>
                                          <tbody>
                                           <?php
                                                $sql = "SELECT IDDOCTOR, NOMBRES, APELLIDOS, ESPECIALIDAD, ESTADO FROM ADM_DOCTOR ";
                                                $query = $conexion->query($sql);

                                                if (!$query) {
                                                    die("Error en la consulta: " . $conexion->error);
                                                }

                                                if ($query->num_rows > 0) {
                                                    while ($valores = mysqli_fetch_array($query)) {
                                                ?>  
                                            <tr>
                                              <td>
                                                <div class="d-flex align-items-center">
                                                  <?php echo avatar_iniciales($valores['NOMBRES'], $valores['APELLIDOS']); ?>
                                                  <div class="ms-3">
                                                    <p class="fw-bold mb-1"><?php echo $valores['NOMBRES'] . " " . $valores['APELLIDOS']; ?></p> 
                                                    <p class="text-muted mb-0"></p>
                                                  </div>
                                                </div>
                                              </td>
                                              <td>
                                                <p class="fw-normal mb-1"><?php echo $valores['ESPECIALIDAD']; ?></p>
                                                <p class="text-muted mb-0">099-555555555</p>
                                              </td>
                                              <td>
                                               <?php if($valores['ESTADO']== "A") {?>
                                                <span class="badge badge-success rounded-pill d-inline"><?php te('doc.active'); ?></span>
                                                <?php
                                               }else{ ?> 
                                                 <span class="badge badge-danger rounded-pill d-inline"><?php te('doc.inactive'); ?></span>
                                                  <?php  }?>
                                              </td>
                                              <!-- <td>Senior</td> -->
                                              <td>
            
                                                           <!-- <button class="btn btn-outline-success fa fa-key"
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#editModalClave" 
                                                                onclick="cargarDatos(<?php echo htmlspecialchars(json_encode($valores), ENT_QUOTES, 'UTF-8'); ?>)">
                                                            </button>
                                                            -->
                                                            
                                                            <button class="btn btn-outline-warning fa fa-edit"
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#editModal" 
                                                                onclick="cargarDatos(<?php echo htmlspecialchars(json_encode($valores), ENT_QUOTES, 'UTF-8'); ?>)">
                                                            </button>
                                                             
                                                             <?php if($valores['ESTADO']== "A") {?>
                                                            <!-- Botón para eliminar con confirmación -->
                                                            <button class="btn-shadow btn btn-outline-danger fa fa-minus-circle" 
                                                                onclick="confirmarEliminacion(<?php echo $valores['IDDOCTOR']; ?>)">
                                                            </button>
                                                            <?php
                                                             }else{ ?> 
                                                             <button class="btn-shadow btn btn-outline-info fa fa-check" 
                                                                onclick="confirmarActivacion(<?php echo $valores['IDDOCTOR']; ?>)">
                                                            </button>
                                                              <?php  }?>
                                                             
                                                </td>
                                            </tr>
                                             <?php 
                                                    }
                                                } else {
                                                    echo "<tr><td colspan='4' class='text-center'>".htmlspecialchars(t('doc.noData'))."</td></tr>";
                                                }
                                                ?>
                                          </tbody>
                                        </table>
                                    </div>
                                </div>
                                    </div>
                                </div>
                            </div>
                            <div class="tab-pane tabs-animation fade" id="tab-content-2" role="tabpanel">
                                <div class="main-card mb-3 card">
                                    <div class="card-body">
                                        <!-- <div id="calendar-bg-events"></div> -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="app-wrapper-footer">
                        <div class="app-footer">
                            <div class="app-footer__inner">
                                
                            </div>
                        </div>
                    </div>    
                </div>
        </div>
    </div>
    <script>
                                            function confirmarEliminacion(idDoctor) {
                                                if (confirm(<?php echo json_encode(t('doc.confirmDeactivate')); ?>)) {
                                                    window.location.href = "class/Desactivar_DoctorV2.php?idDoctor=" + idDoctor;
                                                }
                                            }
                                            function confirmarActivacion(idDoctor) {
                                                if (confirm(<?php echo json_encode(t('doc.confirmActivate')); ?>)) {
                                                    window.location.href = "class/Activar_DoctorV2.php?idDoctor=" + idDoctor;
                                                }
                                            }
                                        </script>
   <script>
    function cargarDatos(usuario) {
        document.getElementById('idDoctor').value = usuario.IDDOCTOR;
        document.getElementById('nombresD').value = usuario.NOMBRES;
        document.getElementById('apellidosD').value = usuario.APELLIDOS;
        
        document.getElementById('especialidadD').value = usuario.ESPECIALIDAD;
      
    }

    function guardarEdicion() {
        var formData = new FormData(document.getElementById("formEditarDoctor"));

        fetch("class/Editar_Doctor.php", {
            method: "POST",
            body: formData
        })
        .then(response => response.text())
        .then(data => {
            alert(data);
            location.reload(); // Recargar la página tras la edición
        })
        .catch(error => console.error("Error:", error));
    }
    
</script>
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editModalLabel"><?php te('doc.editTitle'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <form id="formEditarDoctor">
                    <input type="hidden" id="idDoctor" name="idDoctor">
                    
                    <div class="mb-3">
                        <label class="form-label"><?php te('pf.firstName'); ?></label>
                        <input type="text" class="form-control" id="nombresD" name="nombresD">
                    </div>
                    
                   

                    <div class="mb-3">
                        <label class="form-label"><?php te('pf.lastName'); ?></label>
                        <input type="text" class="form-control" id="apellidosD" name="apellidosD">
                    </div>
                     <div class="mb-3">
                        <label class="form-label"><?php te('doc.specialty'); ?></label>
                        <input type="text" class="form-control" id="especialidadD" name="especialidadD">
                    </div>

                    

                    
                    <button type="button" class="btn btn-primary" onclick="guardarEdicion()"><?php te('common.saveChanges'); ?></button>
                </form>
            </div>
        </div>
    </div>
</div>
<script type="text/javascript" src="./assets/scripts/main.js"></script>

</body>
</html>