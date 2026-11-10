using System;
using System.IO;
using System.Security.Cryptography;

namespace WorkFolio.Agent
{
    /// <summary>
    /// Checks a downloaded update before it is swapped in.
    ///
    /// Two layers, in order:
    ///   1. SHA-256 - the server publishes the hash of the release, so a
    ///      truncated or tampered download is rejected before it is ever run.
    ///   2. Authenticode signature - the hook is here and enforced only once
    ///      <see cref="RequireSignature"/> is turned on (i.e. once the build is
    ///      code-signed). Until then the hash is the guarantee.
    ///
    /// The hash is compared against the value the server sent with the update
    /// description, not against a hash shipped separately, so there is nothing an
    /// attacker controlling only the download can swap.
    /// </summary>
    internal static class UpdateVerifier
    {
        /// <summary>
        /// Flip to true when the release is Authenticode-signed. While false, a
        /// missing signature is not treated as a failure, so an unsigned build
        /// still updates itself on the strength of the SHA-256 check alone.
        /// </summary>
        public static bool RequireSignature = false;

        public static bool VerifySha256(string filePath, string expectedHex, out string detail)
        {
            detail = null;
            if (string.IsNullOrEmpty(expectedHex))
            {
                // Older servers predate the sha256 field. Do not block the update
                // on that alone, but say clearly that it went unverified.
                detail = "No checksum was published; the update was accepted without verification.";
                return true;
            }

            string actual = Sha256(filePath);
            if (actual == null)
            {
                detail = "The downloaded file could not be read for verification.";
                return false;
            }

            bool ok = string.Equals(actual, expectedHex.Trim(), StringComparison.OrdinalIgnoreCase);
            if (!ok)
            {
                detail = "Checksum mismatch - the update was rejected. Expected " +
                         expectedHex.Trim().ToLowerInvariant() + " but the file is " + actual + ".";
            }
            return ok;
        }

        /// <summary>Uppercase or lowercase hex of a file's SHA-256, or null on error.</summary>
        public static string Sha256(string filePath)
        {
            try
            {
                using (FileStream stream = File.OpenRead(filePath))
                using (SHA256 hash = SHA256.Create())
                {
                    byte[] digest = hash.ComputeHash(stream);
                    System.Text.StringBuilder sb = new System.Text.StringBuilder(digest.Length * 2);
                    foreach (byte b in digest) sb.Append(b.ToString("x2"));
                    return sb.ToString();
                }
            }
            catch (Exception)
            {
                return null;
            }
        }

        /// <summary>
        /// Authenticode verification hook. Returns true while signatures are not
        /// required. When <see cref="RequireSignature"/> is set, this is where the
        /// WinVerifyTrust check goes; until a certificate exists it fails closed
        /// so an unsigned update cannot slip through once the switch is on.
        /// </summary>
        public static bool VerifySignature(string filePath, out string detail)
        {
            detail = null;
            if (!RequireSignature) return true;
            detail = "This build requires a signed update, but signature checking is not available in this release.";
            return false;
        }
    }
}
