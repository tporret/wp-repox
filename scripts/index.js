const crypto = require('crypto');
const axios = require('axios');

(async function process() {
  try {
    // Read environment variables
    const webhookURL = process.env.WEBHOOK_URL;
    if (!webhookURL) {
      console.error("Google Chat webhook URL is missing from environment variables.");
      process.exit(1);
    }

    // Simulate receiving GitHub webhook payload and headers
    const payload = JSON.parse(process.env.GITHUB_EVENT_PAYLOAD || '{}');
    const eventType = process.env.GITHUB_EVENT_NAME || 'unknown';

    console.log(`Received GitHub event: ${eventType}`);
    console.log(`Payload: ${JSON.stringify(payload, null, 2)}`);

    // Format the Google Chat message
    const googleChatMessage = formatGoogleChatMessage(eventType, payload);
    if (!googleChatMessage) {
      console.warn("No relevant information to send to Google Chat for this event.");
      process.exit(0);
    }

    // Send the message to Google Chat
    await sendToGoogleChat(googleChatMessage, webhookURL);
    console.log("Notification sent to Google Chat successfully.");
  } catch (error) {
    console.error("Error processing GitHub webhook:", error.message);
    process.exit(1);
  }

  function formatGoogleChatMessage(eventType, payload) {
    let message = "";

    switch (eventType) {
      case 'pull_request':
        const pr = payload.pull_request;
        message = `*GitHub Pull Request:* [${pr.title}](${pr.html_url}) - ${payload.action} by ${pr.user.login}`;
        break;
      case 'issues':
        const issue = payload.issue;
        message = `*GitHub Issue:* [${issue.title}](${issue.html_url}) - ${payload.action} by ${issue.user.login}`;
        break;
      case 'push':
        const branch = payload.ref.split('/').pop();
        const commits = payload.commits || [];
        const pusher = payload.pusher.name;
        message = `*GitHub Push:* ${commits.length} commit(s) to branch *${branch}* by ${pusher}.`;
        if (commits.length > 0) {
          message += `\n> Latest commit: [${commits[0].message.split('\n')[0]}](${commits[0].url})`;
        }
        break;
      case 'release':
        const release = payload.release;
        message = `*GitHub Release:* [${release.name}](${release.html_url}) - ${payload.action} by ${release.author.login}`;
        break;
      default:
        console.info(`Unhandled GitHub event: ${eventType}`);
        return null;
    }

    return message;
  }

  async function sendToGoogleChat(message, webhookURL) {
    try {
      const response = await axios.post(webhookURL, { text: message }, {
        headers: { 'Content-Type': 'application/json' }
      });
      console.log(`Google Chat response: ${response.status} - ${response.statusText}`);
    } catch (error) {
      console.error("Failed to send notification to Google Chat:", error.message);
      throw error;
    }
  }
})();
