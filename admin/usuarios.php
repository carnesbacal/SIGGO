<?php
/** admin/usuarios.php - Gestion de usuarios */
require __DIR__ . '/../config/admin_helpers.php';
require_once __DIR__ . '/../config/tema.php';
requerir_permiso('administrar');   // pantalla solo para administradores

$yo = (int) (usuario_actual()['id'] ?? 0);

if (es_post()) {
    if (!csrf_valido(input('_csrf'))) { flash_set('error','Sesión expirada, intenta de nuevo.'); header('Location: '.url('admin/usuarios.php')); exit; }
    $accion = input('accion');
    if ($accion === 'toggle') {
        $tid = (int) input('id');
        if ($tid === $yo) { flash_set('error','No puedes desactivar tu propia cuenta.'); }
        else { admin_toggle_activo('usuarios', $tid, 'Usuario'); }
        header('Location: '.url('admin/usuarios.php')); exit;
    }
    if ($accion === 'guardar') {
        $id = (int) input('id');
        $usuario = strtolower(trim((string) input('usuario')));
        $nombre  = trim((string) input('nombre_completo'));
        $email   = trim((string) input('email')) ?: null;
        $rol     = (int) input('rol_id');
        $depto   = (int) input('area_id') ?: null;
        $pass    = (string) input('password');
        $err = null;
        if ($usuario === '' || $nombre === '' || $rol <= 0) $err = 'Usuario, nombre y rol son obligatorios.';
        if (!$err && !preg_match('/^[a-z0-9._-]{3,50}$/', $usuario)) $err = 'El usuario solo admite minúsculas, números, punto, guion y guion bajo (3-50).';
        if (!$err) { $dup = db_one("SELECT id FROM usuarios WHERE usuario=:u AND id<>:id", ['u'=>$usuario,'id'=>$id]); if ($dup) $err = 'Ese nombre de usuario ya existe.'; }
        if (!$err && $id === 0 && strlen($pass) < 8) $err = 'La contraseña inicial debe tener al menos 8 caracteres.';
        if (!$err && $id > 0 && $pass !== '' && strlen($pass) < 8) $err = 'La nueva contraseña debe tener al menos 8 caracteres.';
        if ($err) { flash_set('error',$err); header('Location: '.url('admin/usuarios.php')); exit; }
        if ($id > 0) {
            db_exec("UPDATE usuarios SET usuario=:u, nombre_completo=:n, email=:e, rol_id=:r, area_id=:d WHERE id=:id",
                ['u'=>$usuario,'n'=>$nombre,'e'=>$email,'r'=>$rol,'d'=>$depto,'id'=>$id]);
            if ($pass !== '') {
                db_exec("UPDATE usuarios SET password_hash=:h, debe_cambiar_password=1, intentos_fallidos=0, bloqueado_hasta=NULL WHERE id=:id", ['h'=>password_hash($pass, PASSWORD_DEFAULT),'id'=>$id]);
            }
            registrar_auditoria('editar','usuarios',$id,"Editó usuario $usuario");
            flash_set('success','Usuario actualizado.');
        } else {
            db_exec("INSERT INTO usuarios (usuario,password_hash,nombre_completo,email,rol_id,area_id,activo,debe_cambiar_password) VALUES (:u,:h,:n,:e,:r,:d,1,1)",
                ['u'=>$usuario,'h'=>password_hash($pass, PASSWORD_DEFAULT),'n'=>$nombre,'e'=>$email,'r'=>$rol,'d'=>$depto]);
            registrar_auditoria('crear','usuarios',db_last_id(),"Creó usuario $usuario");
            flash_set('success','Usuario creado. Deberá cambiar su contraseña al primer ingreso.');
        }
        header('Location: '.url('admin/usuarios.php')); exit;
    }
}

$rows = db_all("SELECT u.id,u.usuario,u.nombre_completo,u.email,u.rol_id,u.area_id,u.activo,u.ultimo_login,
                       r.nombre AS rol, d.nombre AS depto
                FROM usuarios u INNER JOIN roles r ON u.rol_id=r.id LEFT JOIN areas d ON u.area_id=d.id
                ORDER BY u.activo DESC, u.nombre_completo");
$roles  = db_all("SELECT id, nombre FROM roles WHERE activo=1 ORDER BY id");
$deptos = db_all("SELECT id, nombre FROM areas WHERE activo=1 ORDER BY nombre");
$titulo_pagina = 'Usuarios';
$pagina_activa = 'admin_usuarios';
require __DIR__ . '/../config/header.php';
?>
<div x-data="usuariosCat()">
  <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
    <div><h2 class="font-display text-2xl font-extrabold text-zinc-900">Usuarios</h2><p class="text-xs text-zinc-500 mt-0.5">Cuentas del sistema, roles y contraseñas.</p></div>
    <button @click="nuevo()" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-marca-600 hover:bg-marca-700 text-white text-sm font-semibold shadow-sm"><i data-lucide="plus" class="w-4 h-4"></i> Nuevo usuario</button>
  </div>

  <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm overflow-hidden"><div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-[11px] uppercase tracking-wide text-zinc-400 border-b border-zinc-200 bg-zinc-50">
        <th class="px-4 py-3 font-semibold">Usuario</th><th class="px-4 py-3 font-semibold">Rol</th>
        <th class="px-4 py-3 font-semibold">Área</th><th class="px-4 py-3 font-semibold">Estado</th>
        <th class="px-4 py-3 font-semibold text-right">Acciones</th>
      </tr></thead>
      <tbody class="divide-y divide-zinc-100">
      <?php foreach ($rows as $r): ?>
        <tr class="hover:bg-zinc-50 <?= $r['activo']?'':'opacity-60' ?>">
          <td class="px-4 py-3"><div class="flex items-center gap-2.5"><?= render_avatar(['nombre_completo'=>$r['nombre_completo']], 'w-8 h-8') ?><div><div class="font-medium text-zinc-800"><?= e($r['nombre_completo']) ?></div><div class="text-xs text-zinc-400 font-mono">@<?= e($r['usuario']) ?></div></div></div></td>
          <td class="px-4 py-3"><?= badge($r['rol'], tono(600)) ?></td>
          <td class="px-4 py-3 text-zinc-500"><?= e($r['depto'] ?? '—') ?></td>
          <td class="px-4 py-3"><?php if ($r['activo']): ?><span class="text-xs font-medium text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full">Activo</span><?php else: ?><span class="text-xs font-medium text-zinc-500 bg-zinc-100 px-2 py-0.5 rounded-full">Inactivo</span><?php endif; ?></td>
          <td class="px-4 py-3"><div class="flex items-center justify-end gap-1">
            <button @click='editar(<?= json_encode($r, JSON_UNESCAPED_UNICODE) ?>)' class="p-1.5 rounded-lg text-zinc-500 hover:bg-zinc-100 hover:text-marca-700" title="Editar"><i data-lucide="pencil" class="w-4 h-4"></i></button>
            <?php if ((int)$r['id'] !== $yo): ?>
            <form method="post" class="inline"><?= csrf_input() ?><input type="hidden" name="accion" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button type="submit" class="p-1.5 rounded-lg text-zinc-500 hover:bg-zinc-100 hover:text-marca-700" title="<?= $r['activo']?'Desactivar':'Activar' ?>"><i data-lucide="<?= $r['activo']?'toggle-right':'toggle-left' ?>" class="w-4 h-4"></i></button>
            </form>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>

  <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display:none">
    <div class="absolute inset-0 bg-black/40" @click="open=false"></div>
    <div class="relative bg-white rounded-2xl shadow-xl border border-zinc-200 w-full max-w-lg p-6 max-h-[90vh] overflow-y-auto">
      <h3 class="font-display font-bold text-lg text-zinc-900 mb-4" x-text="form.id ? 'Editar usuario' : 'Nuevo usuario'"></h3>
      <form method="post" class="grid grid-cols-2 gap-3">
        <?= csrf_input() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" :value="form.id">
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Usuario (login) *</label>
          <input type="text" name="usuario" x-model="form.usuario" required maxlength="50" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm lowercase"></div>
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Nombre completo *</label>
          <input type="text" name="nombre_completo" x-model="form.nombre_completo" required maxlength="150" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm"></div>
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Email</label>
          <input type="email" name="email" x-model="form.email" maxlength="150" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm"></div>
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1"></label></div>
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Rol *</label>
          <select name="rol_id" x-model="form.rol_id" required class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm">
            <option value="">— Selecciona —</option>
            <?php foreach ($roles as $ro): ?><option value="<?= (int)$ro['id'] ?>"><?= e($ro['nombre']) ?></option><?php endforeach; ?>
          </select></div>
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Área</label>
          <select name="area_id" x-model="form.area_id" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm">
            <option value="">— Sin asignar —</option>
            <?php foreach ($deptos as $de): ?><option value="<?= (int)$de['id'] ?>"><?= e($de['nombre']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="col-span-2"><label class="block text-xs font-semibold text-zinc-500 mb-1"><span x-text="form.id ? 'Nueva contraseña (opcional)' : 'Contraseña inicial *'"></span></label>
          <input type="text" name="password" x-model="form.password" :required="!form.id" minlength="8" placeholder="Mínimo 8 caracteres" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm font-mono">
          <p class="text-[11px] text-zinc-400 mt-1" x-show="form.id">Déjalo en blanco para no cambiar la contraseña. Si la cambias, el usuario deberá renovarla al entrar.</p></div>
        <div class="col-span-2 flex justify-end gap-2 pt-1">
          <button type="button" @click="open=false" class="px-4 py-2 rounded-lg text-sm font-medium text-zinc-600 hover:bg-zinc-100">Cancelar</button>
          <button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-marca-600 hover:bg-marca-700 shadow-sm">Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
function usuariosCat(){return{
  open:false,
  form:{id:0,usuario:'',nombre_completo:'',email:'',rol_id:'',area_id:'',password:''},
  nuevo(){ this.form={id:0,usuario:'',nombre_completo:'',email:'',rol_id:'',area_id:'',password:''}; this.open=true; },
  editar(r){ this.form={id:r.id,usuario:r.usuario,nombre_completo:r.nombre_completo,email:r.email||'',rol_id:String(r.rol_id||''),area_id:r.area_id?String(r.area_id):'',password:''}; this.open=true; }
}}
</script>
<?php require __DIR__ . '/../config/footer.php'; ?>
