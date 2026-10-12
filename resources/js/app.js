import Swal from 'sweetalert2';

/**
 * HarmaWorkspace notifications.
 *
 * Every success, warning and error is shown as a SweetAlert2 toast, whether it
 * comes from a Livewire action (`notify` event), a flash message after a
 * redirect, or a failed request (403, 419, 500, network).
 */
const Toast = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    showCloseButton: true,
    timerProgressBar: true,
    customClass: { popup: 'hw-toast' },
    didOpen: (toast) => {
        toast.addEventListener('mouseenter', Swal.stopTimer);
        toast.addEventListener('mouseleave', Swal.resumeTimer);
    },
});

const titles = {
    success: 'Berhasil',
    error: 'Gagal',
    warning: 'Perhatian',
    info: 'Info',
};

export function notify(type = 'info', message = '', title = null) {
    const icon = titles[type] ? type : 'info';

    Toast.fire({
        icon,
        title: title ?? titles[icon],
        text: message,
        timer: icon === 'error' || icon === 'warning' ? 6000 : 3500,
    });
}

window.notify = notify;

/**
 * Confirmation dialog for sensitive actions. Resolves to true when confirmed.
 * Usage in Blade: x-on:click="hwConfirm({...}).then(ok => ok && $wire.archive())"
 * The backend still authorizes every action; this is only a UX safeguard.
 */
export function hwConfirm({ title, text = '', confirmText = 'Lanjutkan', cancelText = 'Batal', danger = false } = {}) {
    return Swal.fire({
        title,
        text,
        icon: danger ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: cancelText,
        reverseButtons: true,
        focusCancel: danger,
        buttonsStyling: false,
        customClass: {
            popup: 'hw-confirm',
            confirmButton: danger ? 'hw-confirm-btn hw-confirm-danger' : 'hw-confirm-btn hw-confirm-primary',
            cancelButton: 'hw-confirm-btn hw-confirm-cancel',
        },
    }).then((result) => result.isConfirmed);
}

window.hwConfirm = hwConfirm;

const requestErrors = {
    401: 'Sesi Anda telah berakhir. Silakan login kembali.',
    403: 'Anda tidak memiliki akses untuk melakukan aksi ini.',
    404: 'Data tidak ditemukan. Mungkin sudah dihapus atau dipindahkan.',
    419: 'Halaman sudah terlalu lama terbuka. Muat ulang halaman lalu coba lagi.',
    422: 'Data yang dikirim tidak valid.',
    429: 'Terlalu banyak permintaan. Tunggu sebentar lalu coba lagi.',
};

function showFlash() {
    const holder = document.querySelector('[data-flash-notify]');

    if (!holder) {
        return;
    }

    try {
        const { type, message } = JSON.parse(holder.dataset.flashNotify);
        notify(type, message);
    } catch (error) {
        console.error(error);
    } finally {
        holder.remove();
    }
}

document.addEventListener('livewire:init', () => {
    window.Livewire.on('notify', ({ type, message, title }) => notify(type, message, title));

    window.Livewire.interceptRequest(({ onError, onFailure }) => {
        onError(({ response, preventDefault }) => {
            if (response.status === 401 || response.status === 419) {
                preventDefault();
                notify('warning', requestErrors[response.status]);
                setTimeout(() => window.location.reload(), 2500);

                return;
            }

            preventDefault();
            notify('error', requestErrors[response.status] ?? 'Terjadi kesalahan pada sistem. Silakan coba lagi atau hubungi administrator.');
        });

        onFailure(() => {
            notify('error', 'Tidak dapat terhubung ke server. Periksa koneksi internet Anda.');
        });
    });
});

document.addEventListener('DOMContentLoaded', showFlash);
document.addEventListener('livewire:navigated', showFlash);
