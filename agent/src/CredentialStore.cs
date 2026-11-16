using System;
using System.IO;
using System.Security.Cryptography;
using System.Text;
using System.Web.Script.Serialization;

namespace WorkFolio.Agent
{
    /// <summary>
    /// Remembers the last sign-in so the employee does not retype it every
    /// morning. The password is protected with the Windows Data Protection API
    /// (per-user scope): the ciphertext can only be decrypted by the same Windows
    /// account on the same machine, so copying the file to another PC or another
    /// user's profile yields nothing.
    ///
    /// The file lives beside the shift ledger in the per-user state folder, never
    /// in the shipped package, so one employee's credentials can never be
    /// pre-filled on a colleague's machine.
    /// </summary>
    internal static class CredentialStore
    {
        private sealed class Payload
        {
            public string Email = string.Empty;
            public string Password = string.Empty;
        }

        private static string FilePath
        {
            get { return AgentSettings.StatePathFor("credentials.dat"); }
        }

        public static void Save(string email, string password)
        {
            try
            {
                Payload payload = new Payload();
                payload.Email = email ?? string.Empty;
                payload.Password = password ?? string.Empty;

                JavaScriptSerializer serializer = new JavaScriptSerializer();
                byte[] plain = Encoding.UTF8.GetBytes(serializer.Serialize(payload));
                byte[] cipher = ProtectedData.Protect(plain, null, DataProtectionScope.CurrentUser);
                File.WriteAllText(FilePath, Convert.ToBase64String(cipher), Encoding.ASCII);
            }
            catch (Exception)
            {
                // A machine that forbids DPAPI is not a reason to block sign-in.
            }
        }

        /// <summary>
        /// Returns the remembered email and password, or empty strings when
        /// nothing is stored or the file cannot be decrypted on this account.
        /// </summary>
        public static void Load(out string email, out string password)
        {
            email = string.Empty;
            password = string.Empty;
            try
            {
                if (!File.Exists(FilePath)) return;
                string text = File.ReadAllText(FilePath, Encoding.ASCII);
                if (string.IsNullOrEmpty(text)) return;

                byte[] cipher = Convert.FromBase64String(text);
                byte[] plain = ProtectedData.Unprotect(cipher, null, DataProtectionScope.CurrentUser);

                JavaScriptSerializer serializer = new JavaScriptSerializer();
                Payload payload = serializer.Deserialize<Payload>(Encoding.UTF8.GetString(plain));
                if (payload == null) return;
                email = payload.Email ?? string.Empty;
                password = payload.Password ?? string.Empty;
            }
            catch (Exception)
            {
                email = string.Empty;
                password = string.Empty;
            }
        }

        public static void Clear()
        {
            try { if (File.Exists(FilePath)) File.Delete(FilePath); }
            catch (Exception) { }
        }
    }
}
