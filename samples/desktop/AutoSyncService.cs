using ChampyStore.Repositories;
using System;
using System.Threading.Tasks;
using System.Windows.Threading;

namespace ChampyStore.Services
{
    public static class AutoSyncService
    {
        private static DispatcherTimer timer;
        private static bool isRunning = false;

        public static bool IsEnabled { get; private set; } = false;

        public static void LoadAndStartIfEnabled()
        {
            AppSettingsRepository repo =
                new AppSettingsRepository();

            string value =
                repo.GetValue("AutoSyncEnabled", "0");

            if (value == "1")
                Start(5, false);
        }

        public static void Start(int minutes = 5, bool save = true)
        {
            if (timer != null)
                timer.Stop();

            timer = new DispatcherTimer();
            timer.Interval = TimeSpan.FromMinutes(minutes);
            timer.Tick += async (s, e) => await RunSyncAsync();

            IsEnabled = true;
            timer.Start();

            if (save)
            {
                new AppSettingsRepository()
                    .SetValue("AutoSyncEnabled", "1");
            }
        }

        public static void Stop()
        {
            if (timer != null)
                timer.Stop();

            IsEnabled = false;

            new AppSettingsRepository()
                .SetValue("AutoSyncEnabled", "0");
        }

        // Arrête le minuteur sans changer le réglage (déconnexion).
        public static void Pause()
        {
            if (timer != null)
                timer.Stop();

            IsEnabled = false;
        }

        private static async Task RunSyncAsync()
        {
            if (isRunning)
                return;

            isRunning = true;

            try
            {
                SyncService syncService =
                    new SyncService();

                bool success =
                    await syncService.SynchroniserAsync();

                if (success)
                {
                    RefreshManager.Refresh();
                }
            }
            finally
            {
                isRunning = false;
            }
        }
    }
}