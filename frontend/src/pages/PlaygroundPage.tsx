import React, { useEffect, useRef, useState } from "react";
import { useAuth } from "../contexts/AuthContext";
import { apiFetch, ApiError } from "../api/client";
import {
  Send,
  Sparkles,
  AlertCircle,
  Coins,
  Cpu,
  RefreshCw,
  SlidersHorizontal,
  Bot,
  User as UserIcon,
} from "lucide-react";

interface Model {
  id: string;
  public_id: string;
  display_name: string;
  provider_slug: string;
  input_price_per_million: number;
  output_price_per_million: number;
  max_input_tokens: number;
  max_output_tokens: number;
  supports_streaming: boolean;
}

interface Message {
  role: "user" | "assistant" | "system";
  content: string;
}

export const PlaygroundPage: React.FC = () => {
  const { wallet, refreshWallet } = useAuth();
  const [models, setModels] = useState<Model[]>([]);
  const [selectedModel, setSelectedModel] = useState<string>("mock/echo-v1");
  const [messages, setMessages] = useState<Message[]>([
    {
      role: "assistant",
      content: "Halo! Saya adalah AI Router. Pilih model yang tersedia di panel atas, lalu ketik pesan untuk mulai mencoba.",
    },
  ]);
  const [inputPrompt, setInputPrompt] = useState("");
  const [isStreaming, setIsStreaming] = useState(true);
  const [loading, setLoading] = useState(false);
  const [errorBanner, setErrorBanner] = useState<{ code: string; message: string; reqId: string } | null>(null);
  const messagesEndRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const fetchModels = async () => {
      try {
        const res = await apiFetch<Model[]>("/api/v1/models").catch(async () => {
          const openAiModels = await apiFetch<{ data: { id: string }[] }>("/v1/models");
          return openAiModels.data.map((m) => ({
            id: m.id,
            public_id: m.id,
            display_name: m.id,
            provider_slug: m.id.split("/")[0] || "provider",
            input_price_per_million: 1500,
            output_price_per_million: 3000,
            max_input_tokens: 4096,
            max_output_tokens: 2048,
            supports_streaming: true,
          }));
        });
        setModels(res);
        if (res.length > 0 && !res.find((m) => m.public_id === selectedModel)) {
          setSelectedModel(res[0].public_id);
        }
      } catch (e) {
        console.error("Gagal memuat model:", e);
      }
    };

    fetchModels();
  }, []);

  const scrollToBottom = () => {
    messagesEndRef.current?.scrollIntoView({ behavior: "smooth" });
  };

  useEffect(() => {
    scrollToBottom();
  }, [messages, loading]);

  const currentModelObj = models.find((m) => m.public_id === selectedModel);

  const handleSendMessage = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!inputPrompt.trim() || loading) return;

    const userText = inputPrompt.trim();
    setInputPrompt("");
    setErrorBanner(null);

    const newMessages: Message[] = [...messages, { role: "user", content: userText }];
    setMessages(newMessages);
    setLoading(true);

    if (isStreaming) {
      // ---------------- Streaming via SSE ----------------
      try {
        const response = await fetch("/v1/chat/completions", {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            model: selectedModel,
            messages: newMessages.filter((m) => m.role !== "system"),
            stream: true,
          }),
        });

        if (!response.ok) {
          const errData = await response.json().catch(() => ({}));
          const err = errData.error || {};
          setErrorBanner({
            code: err.code || "unknown_error",
            message: err.message || "Gagal memproses streaming.",
            reqId: err.request_id || "req_err",
          });
          setLoading(false);
          await refreshWallet();
          return;
        }

        const reader = response.body?.getReader();
        const decoder = new TextDecoder();
        let assistantReply = "";

        // Add empty assistant bubble
        setMessages((prev) => [...prev, { role: "assistant", content: "" }]);

        if (reader) {
          let done = false;
          while (!done) {
            const { value, done: readerDone } = await reader.read();
            done = readerDone;
            if (value) {
              const textChunk = decoder.decode(value, { stream: true });
              const lines = textChunk.split("\n");

              for (const line of lines) {
                if (line.startsWith("data: ")) {
                  const dataStr = line.slice(6).trim();
                  if (dataStr === "[DONE]") {
                    done = true;
                    break;
                  }
                  try {
                    const parsed = JSON.parse(dataStr);
                    if (parsed.error) {
                      setErrorBanner({
                        code: parsed.error.code,
                        message: parsed.error.message,
                        reqId: parsed.error.request_id,
                      });
                      break;
                    }
                    const content = parsed.choices?.[0]?.delta?.content || "";
                    if (content) {
                      assistantReply += content;
                      setMessages((prev) => {
                        const updated = [...prev];
                        updated[updated.length - 1] = {
                          role: "assistant",
                          content: assistantReply,
                        };
                        return updated;
                      });
                    }
                  } catch {
                    // Skip malformed chunks
                  }
                }
              }
            }
          }
        }
      } catch (err: any) {
        setErrorBanner({
          code: "network_error",
          message: err.message || "Terjadi gangguan jaringan.",
          reqId: "req_net",
        });
      } finally {
        setLoading(false);
        await refreshWallet();
      }
    } else {
      // ---------------- Regular Non-streaming ----------------
      try {
        const res = await apiFetch<any>("/v1/chat/completions", {
          method: "POST",
          body: JSON.stringify({
            model: selectedModel,
            messages: newMessages.filter((m) => m.role !== "system"),
            stream: false,
          }),
        });

        const replyContent = res.choices?.[0]?.message?.content || "";
        setMessages((prev) => [...prev, { role: "assistant", content: replyContent }]);
      } catch (err: any) {
        if (err instanceof ApiError) {
          setErrorBanner({
            code: err.code,
            message: err.message,
            reqId: err.requestId,
          });
        } else {
          setErrorBanner({
            code: "internal_error",
            message: "Gagal memproses permintaan chat.",
            reqId: "req_unknown",
          });
        }
      } finally {
        setLoading(false);
        await refreshWallet();
      }
    }
  };

  return (
    <div className="flex flex-col h-[calc(100vh-7.5rem)] sm:h-[calc(100vh-11rem)] min-h-[460px] bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
      {/* Top Header Bar */}
      <div className="px-3 sm:px-6 py-2.5 sm:py-3.5 border-b border-slate-200 bg-slate-50/70 flex flex-wrap items-center justify-between gap-2.5 sm:gap-4">
        {/* Model Selector */}
        <div className="flex items-center space-x-2 sm:space-x-3 flex-wrap gap-y-1">
          <div className="flex items-center space-x-1.5 sm:space-x-2">
            <Cpu className="w-4 h-4 text-indigo-600 shrink-0" />
            <span className="text-xs font-bold text-slate-700 uppercase tracking-wider">Model:</span>
          </div>

          <select
            value={selectedModel}
            onChange={(e) => setSelectedModel(e.target.value)}
            className="px-2.5 sm:px-3 py-1.5 text-xs font-semibold rounded-xl border border-slate-300 bg-white text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 shadow-sm max-w-[200px] sm:max-w-none truncate"
          >
            {models.map((m) => (
              <option key={m.public_id} value={m.public_id}>
                {m.public_id} ({m.provider_slug})
              </option>
            ))}
          </select>

          {currentModelObj && (
            <div className="hidden sm:flex items-center space-x-2 text-[11px] text-slate-500 bg-slate-100 px-2.5 py-1 rounded-lg border border-slate-200">
              <span>Input: Rp{currentModelObj.input_price_per_million}/1M</span>
              <span>•</span>
              <span>Output: Rp{currentModelObj.output_price_per_million}/1M</span>
            </div>
          )}
        </div>

        {/* Options & Wallet Badge */}
        <div className="flex items-center space-x-4">
          <label className="flex items-center space-x-2 cursor-pointer text-xs font-medium text-slate-600">
            <input
              type="checkbox"
              checked={isStreaming}
              onChange={(e) => setIsStreaming(e.target.checked)}
              className="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
            />
            <span>Streaming SSE</span>
          </label>

          <div className="flex items-center space-x-1.5 text-xs font-semibold px-2.5 py-1 bg-emerald-50 text-emerald-700 rounded-lg border border-emerald-200">
            <Coins className="w-3.5 h-3.5 text-emerald-600" />
            <span>{wallet ? wallet.balance_credits.toLocaleString("id-ID") : 0} Kredit</span>
          </div>
        </div>
      </div>

      {/* Error Alert Envelope */}
      {errorBanner && (
        <div className="px-6 py-3 bg-rose-50 border-b border-rose-200 text-rose-800 text-xs flex items-center justify-between">
          <div className="flex items-center space-x-2">
            <AlertCircle className="w-4 h-4 text-rose-600 flex-shrink-0" />
            <span>
              <strong>[{errorBanner.code}]:</strong> {errorBanner.message}
            </span>
          </div>
          <span className="font-mono text-[10px] text-rose-500 bg-rose-100 px-2 py-0.5 rounded">
            {errorBanner.reqId}
          </span>
        </div>
      )}

      {/* Chat Messages Log */}
      <div className="flex-1 p-6 overflow-y-auto space-y-4">
        {messages.map((m, idx) => (
          <div
            key={idx}
            className={`flex items-start space-x-3 ${
              m.role === "user" ? "flex-row-reverse space-x-reverse" : "flex-row"
            }`}
          >
            <div
              className={`w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0 text-white shadow-sm ${
                m.role === "user" ? "bg-slate-800" : "bg-indigo-600"
              }`}
            >
              {m.role === "user" ? <UserIcon className="w-4 h-4" /> : <Bot className="w-4 h-4" />}
            </div>

            <div
              className={`max-w-[88%] sm:max-w-[80%] rounded-2xl px-3 sm:px-4 py-2 sm:py-3 text-xs sm:text-sm leading-relaxed ${
                m.role === "user"
                  ? "bg-slate-900 text-white shadow-sm"
                  : "bg-slate-100 text-slate-900 border border-slate-200/60"
              }`}
            >
              <div className="whitespace-pre-wrap">{m.content || (loading && idx === messages.length - 1 ? "Sedang mengetik..." : "")}</div>
            </div>
          </div>
        ))}
        <div ref={messagesEndRef} />
      </div>

      {/* Input Area */}
      <form onSubmit={handleSendMessage} className="p-4 border-t border-slate-200 bg-white">
        <div className="relative flex items-center">
          <textarea
            rows={1}
            value={inputPrompt}
            onChange={(e) => setInputPrompt(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === "Enter" && !e.shiftKey) {
                e.preventDefault();
                handleSendMessage(e);
              }
            }}
            placeholder="Tulis pesan... (Tekan Enter untuk kirim, Shift+Enter untuk baris baru)"
            className="w-full pl-4 pr-12 py-3 rounded-xl border border-slate-300 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm resize-none"
          />

          <button
            type="submit"
            disabled={loading || !inputPrompt.trim()}
            className="absolute right-2.5 p-2 bg-indigo-600 hover:bg-indigo-700 disabled:bg-slate-200 text-white disabled:text-slate-400 rounded-lg transition-colors shadow-sm"
            title="Kirim Pesan"
          >
            {loading ? (
              <RefreshCw className="w-4 h-4 animate-spin" />
            ) : (
              <Send className="w-4 h-4" />
            )}
          </button>
        </div>
        <div className="flex items-center justify-between text-[11px] text-slate-400 px-2 pt-2">
          <span>Model: {selectedModel}</span>
          <span>Biaya dipotong atomik sesuai token aktual</span>
        </div>
      </form>
    </div>
  );
};
