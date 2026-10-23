<?php
ob_start();
session_start();
require_once("class/funciones.php");
require_once("class/conexionBD.php");
require_once(__DIR__ . "/class/ui.php");
require_once(__DIR__ . "/lang/i18n.php");
require_once(__DIR__ . "/class/permisos.php");

if (!isset($_SESSION["rol"], $_SESSION["iduser"])) {
    header("Location: break.php");
    exit();
}
if (isset($_SESSION['expire']) && time() > $_SESSION['expire']) {
    session_destroy();
    header("Location: expirada.php");
    exit();
}
requerir('panel.usuarios');
$conexion=conectarse();

$rolesHtml = '';
$sqlRoles  = "SELECT IDADM_ROL, CARGO FROM ADM_ROL WHERE ESTADO = 'A' ORDER BY IDADM_ROL";
$queryRoles = $conexion->query($sqlRoles);
if ($queryRoles) {
    while ($rolFila = mysqli_fetch_array($queryRoles)) {
        $rolesHtml .= '<option value="' . htmlspecialchars($rolFila['IDADM_ROL'], ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($rolFila['CARGO'], ENT_QUOTES, 'UTF-8') . '</option>';
    }
}
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
    <meta http-equiv="Content-Language" content="<?php echo current_lang(); ?>">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title><?php te('ucreate.pageTitle'); ?></title>
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
    <link href="./main.css" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.5.1.js"></script>
<script src="https://cdn.datatables.net/1.13.2/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.2/js/dataTables.bootstrap4.min.js"></script>
<link rel="stylesheet" type="text/css" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/4.5.2/css/bootstrap.css">
<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.2/css/dataTables.bootstrap4.min.css">



    </head>
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
                                                <?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?>
                                            </div>
                                            <div class="widget-subheading">
                                                <?php echo htmlspecialchars($_SESSION['rol'] ?? ''); ?>
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
                                        <i class="pe-7s-add-user icon-gradient bg-warm-flame">
                                        </i>
                                    </div>
                                    <div><?php te('pcreate.newTitle'); ?>
                                        <div class="page-title-subheading"><?php te('ucreate.newSub'); ?>
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
                                                <h5 class="card-title"><?php te('ucreate.info'); ?></h5>
                                                <form class="needs-validation" novalidate method="post" action="class/Insert_Usuario.php">
                                                    <div class="form-row">
                                                        <div class="col-md-4 mb-3">
                                                            <label for="validationCustom01"><?php te('pf.firstName'); ?></label>
                                                            <input type="text" class="form-control" id="validationCustom01" name="nombres" placeholder="<?php te('pf.firstName'); ?>" value="" required>
                                                            <div class="valid-feedback">
                                                                Looks good!
                                                            </div>
                                                        </div>
                                                        <div class="col-md-4 mb-3">
                                                            <label for="validationCustom02"><?php te('pf.lastName'); ?></label>
                                                            <input type="text" class="form-control" id="validationCustom02"  name="apellidos" placeholder="<?php te('pf.lastName'); ?>" value="" required>
                                                            <div class="valid-feedback">
                                                                Looks good!
                                                            </div>
                                                        </div>
                                                       <div class="col-md-4 mb-3">
                                                           <label for="validationCustom04"><?php te('pf.phone'); ?></label>
                                                           <input type="text" class="form-control" id="validationCustom04" name="telefono" placeholder="<?php te('pf.phone'); ?>" required>
                                                           <div class="invalid-feedback">
                                                               Please provide a valid state.
                                                           </div>
                                                       </div>
                                                    </div>
                                                    <div class="form-row">
                                                       <div class="col-md-8 mb-3">
                                                           <label for="validationCustomCorreo"><?php te('pf.email'); ?></label>
                                                           <input type="email" class="form-control" id="validationCustomCorreo" name="correo" placeholder="name@mail.com">
                                                           <small class="text-muted"><?php te('ucreate.emailHelp'); ?></small>
                                                       </div>
                                                    </div>
                                                    <div class="form-row">
                                                        <div class="col-md-4 mb-3">
                                                            <label for="validationCustomUsername"><?php te('ucreate.username'); ?></label>
                                                            <div class="input-group">
                                                                <div class="input-group-prepend">
                                                                    <span class="input-group-text" id="inputGroupPrepend"><i class="pe-7s-user"> </i></span>
                                                                </div>
                                                                <input type="text" class="form-control" id="validationCustomUsername" name="usuario" placeholder="<?php te('ucreate.username'); ?>" aria-describedby="inputGroupPrepend" required>
                                                                <div class="invalid-feedback">
                                                                    Please choose a username.
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-4 mb-3">
                                                            <label for="validationCustom03"><?php te('ucreate.password'); ?></label>
                                                            <input type="text" class="form-control" id="validationCustom03" name="clave" placeholder="<?php te('ucreate.password'); ?>" required>
                                                            <div class="invalid-feedback">
                                                                Please provide a valid Password.
                                                            </div>
                                                        </div>
                                                        


                                                        <div class="col-md-4 mb-3">
                                                            <label for="validationCustom05"><?php te('ucreate.role'); ?></label>
                                                            <div class="position-relative form-group">
                                                                <select  id="validationCustom05" name="Idrol" class="form-control" required>
                                                                <option value="0">Default Select</option>
                                                                <?php echo $rolesHtml; ?>
                                                            </select>
                                                        </div>
                                                         <div class="invalid-feedback">
                                                                Please provide a valid rol.
                                                            </div>
                                                        </div>
                                                    </div>
                                                   
                                                    <button class="btn btn-primary" type="submit"><?php te('ucreate.registerBtn'); ?></button>
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
            <th><?php te('ucreate.th.contact'); ?></th>

            <th><?php te('ucreate.role'); ?></th>
            <th><?php te('ucreate.th.status'); ?></th>
            <th><?php te('pcreate.th.actions'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php
        // Columnas opcionales: NPI y LICENSE_ID (las agrega migrar_credenciales_doctor.php)
        $dbName = $conexion->query("SELECT DATABASE() AS db")->fetch_assoc()['db'];
        $colUsr = function($col) use ($conexion, $dbName) {
            return (int)$conexion->query(
                "SELECT COUNT(*) c FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA='$dbName' AND TABLE_NAME='ADM_USUARIO' AND COLUMN_NAME='$col'"
            )->fetch_assoc()['c'] > 0;
        };
        $selNpi     = $colUsr('NPI')        ? ', A.NPI'        : ", '' AS NPI";
        $selLicense = $colUsr('LICENSE_ID') ? ', A.LICENSE_ID' : ", '' AS LICENSE_ID";
        $sql = "SELECT A.IDADM_USUARIO, A.NOMBRES, A.APELLIDOS, A.TELEFONO, A.USUARIO, A.IDAGENCIA, A.IMG, A.ESTADO, B.CARGO, A.IDADM_ROL $selNpi $selLicense FROM ADM_USUARIO A, ADM_ROL B WHERE A.IDADM_ROL= B.IDADM_ROL ORDER BY A.ESTADO ASC, A.NOMBRES";
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
                        <p class="text-muted mb-0"><?php echo $valores['USUARIO']; ?></p>
                    </div>
                </div>
            </td>
            <td>
            <p class="fw-normal mb-1"><?php echo $valores['TELEFONO']; ?></p>
            </td>
            <td>
            <p class="fw-normal mb-1"><?php echo $valores['CARGO']; ?></p>
            </td>

            <td>
            <?php if ($valores['ESTADO'] === 'A'): ?>
                <span class="badge bg-success"><?php te('common.active'); ?></span>
            <?php else: ?>
                <span class="badge bg-secondary"><?php te('common.inactive'); ?></span>
            <?php endif; ?>
            </td>

            <td>

                                                            <button class="btn btn-outline-success fa fa-key"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#editModalClave"
                                                                onclick="cargarDatosClave(<?php echo htmlspecialchars(json_encode($valores), ENT_QUOTES, 'UTF-8'); ?>)">
                                                            </button>

                                                            <button class="btn btn-outline-warning fa fa-edit"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#editModal"
                                                                onclick="cargarDatos(<?php echo htmlspecialchars(json_encode($valores), ENT_QUOTES, 'UTF-8'); ?>)">
                                                            </button>
                                                            <?php if ($valores['ESTADO'] === 'A'): ?>
                                                            <!-- Desactivar con confirmación -->
                                                            <button class="btn-shadow btn btn-outline-danger fa fa-minus-circle"
                                                                title="<?php te('common.deactivate'); ?>"
                                                                onclick="confirmarEliminacion(<?php echo $valores['IDADM_USUARIO']; ?>)">
                                                            </button>
                                                            <?php else: ?>
                                                            <!-- Reactivar con confirmación -->
                                                            <button class="btn-shadow btn btn-outline-success fa fa-check-circle"
                                                                title="<?php te('common.reactivate'); ?>"
                                                                onclick="confirmarActivacion(<?php echo $valores['IDADM_USUARIO']; ?>)">
                                                            </button>
                                                            <?php endif; ?>
            </td>
        </tr>
        <?php
            }
        } else {
            echo "<tr><td colspan='5' class='text-center'>No hay datos disponibles</td></tr>";
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
                                            function confirmarEliminacion(idUsuario) {
                                                if (confirm(<?php echo json_encode(t('ucreate.js.confirmDeact')); ?>)) {
                                                    window.location.href = "class/Desactivar_UsuarioV2.php?idUsuario=" + idUsuario;
                                                }
                                            }
                                            function confirmarActivacion(idUsuario) {
                                                if (confirm(<?php echo json_encode(t('ucreate.js.confirmReact')); ?>)) {
                                                    window.location.href = "class/Activar_UsuarioV2.php?idUsuario=" + idUsuario;
                                                }
                                            }
                                        </script>
   <script>
    function cargarDatos(usuario) {
        document.getElementById('idUsuario').value = usuario.IDADM_USUARIO;
        document.getElementById('nombres').value = usuario.NOMBRES;
        document.getElementById('apellidos').value = usuario.APELLIDOS;
        
        document.getElementById('telefono').value = usuario.TELEFONO;
        document.getElementById('usuario').value = usuario.USUARIO;
        document.getElementById('idAgencia').value = usuario.IDAGENCIA;
         document.getElementById('idRol').value = usuario.IDADM_ROL;
        document.getElementById('npi').value       = usuario.NPI        || '';
        document.getElementById('licenseId').value = usuario.LICENSE_ID || '';

    }
    function cargarDatosClave(u) {
        document.getElementById('claveUserId').value = u.IDADM_USUARIO;
        document.getElementById('claveUserNombre').textContent =
            ((u.NOMBRES || '') + ' ' + (u.APELLIDOS || '')).trim() + (u.USUARIO ? ' (' + u.USUARIO + ')' : '');
        document.getElementById('claveNueva').value = '';
    }

    function guardarEdicion() {
        var formData = new FormData(document.getElementById("formEditar"));

        fetch("class/Editar_Usuario.php", {
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
    function guardarEdicionClave() {
        var nueva = document.getElementById('claveNueva').value;
        if (!nueva.trim()) { alert(<?php echo json_encode(t('ucreate.pw.enter')); ?>); return; }
        if (nueva.length < 6) { alert(<?php echo json_encode(t('profile.js.min6')); ?>); return; }

        var formData = new FormData(document.getElementById("formEditarClave"));

        fetch("class/Editar_Usuario_Clave.php", {
            method: "POST",
            body: formData
        })
        .then(response => response.text())
        .then(data => {
            data = (data || '').trim();
            if (data === 'OK') {
                alert(<?php echo json_encode(t('ucreate.pw.updated')); ?>);
                location.reload();
            } else if (data === 'CORTA') {
                alert(<?php echo json_encode(t('profile.js.min6')); ?>);
            } else if (data === 'INCOMPLETO') {
                alert(<?php echo json_encode(t('ucreate.pw.enter')); ?>);
            } else {
                alert(<?php echo json_encode(t('ucreate.pw.error')); ?> + (data ? '\n' + data : ''));
            }
        })
        .catch(() => alert(<?php echo json_encode(t('common.js.connError')); ?>));
    }
</script>
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editModalLabel"><?php te('ucreate.editUserTitle'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php te('common.close'); ?>"></button>
            </div>
            <div class="modal-body">
                <form id="formEditar">
                    <input type="hidden" id="idUsuario" name="idUsuario">

                    <div class="mb-3">
                        <label class="form-label"><?php te('pf.firstName'); ?></label>
                        <input type="text" class="form-control" id="nombres" name="nombres">
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><?php te('pf.lastName'); ?></label>
                        <input type="text" class="form-control" id="apellidos" name="apellidos">
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><?php te('ucreate.username'); ?></label>
                        <input type="text" class="form-control" id="usuario" name="usuario">
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-danger"><?php te('ucreate.resetPassword'); ?></label>
                        <input type="text" class="form-control" id="clave" name="clave">
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><?php te('pf.phone'); ?></label>
                        <input type="text" class="form-control" id="telefono" name="telefono">
                    </div>


                    <div class="mb-3">
                        <label class="form-label"><?php te('ucreate.role'); ?></label>
                        <select name="idRol" id="idRol" class="form-control" required>
                                                                <option value="0">Default Select</option>
                                                                <?php echo $rolesHtml; ?>
                                                                </select>
                    </div>
                    

                    <div class="mb-3">
                        <label class="form-label"><?php te('ucreate.branch'); ?></label>
                                                                <select name="idAgencia" id="idAgencia" class="form-control" required>
                                                                <option>Default Select</option>
                                                                <option value="1">Sucursal 1</option>
                                                                <option value="2">Sucursal 2</option>
                                                                </select>

                    </div>

                    <hr>
                    <h6 class="text-muted mb-2"><i class="bi bi-patch-check me-1"></i><?php te('ucreate.credentials'); ?></h6>
                    <div class="row g-2">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?php te('ucreate.npi'); ?></label>
                            <input type="text" class="form-control" id="npi" name="npi" maxlength="20" placeholder="<?php te('ucreate.npiPh'); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?php te('ucreate.license'); ?></label>
                            <input type="text" class="form-control" id="licenseId" name="license_id" maxlength="60" placeholder="<?php te('ucreate.licensePh'); ?>">
                        </div>
                    </div>

                    <button type="button" class="btn btn-primary" onclick="guardarEdicion()"><?php te('common.saveChanges'); ?></button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="editModalClave" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editModalLabel"><?php te('ucreate.editPassTitle'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php te('common.close'); ?>"></button>
            </div>
            <div class="modal-body">
                <form id="formEditarClave">
                    <input type="hidden" id="claveUserId" name="idUsuarioCl">
                    <p class="mb-3 small text-muted">
                        <i class="bi bi-person"></i> <?php te('ucreate.pw.for'); ?>:
                        <strong id="claveUserNombre" class="text-dark"></strong>
                    </p>
                    <div class="mb-3">
                        <label class="form-label text-danger"><?php te('ucreate.resetPasswordLc'); ?></label>
                        <input type="text" class="form-control" id="claveNueva" name="clave" minlength="6" placeholder="<?php te('ucreate.password'); ?>" autocomplete="new-password">
                        <small class="text-muted"><?php te('profile.minChars'); ?></small>
                    </div>
                    <button type="button" class="btn btn-primary" onclick="guardarEdicionClave()"><?php te('common.saveChanges'); ?></button>
                </form>
            </div>
        </div>
    </div>
</div>
<script type="text/javascript" src="./assets/scripts/main.js"></script>

</body>
</html>