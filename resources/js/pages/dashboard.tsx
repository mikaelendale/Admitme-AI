'use client'

import { useState } from 'react'
import { Link } from '@inertiajs/react'
import {
  Home,
  BookOpen,
  FileText,
  Bookmark,
  Clock,
  Settings,
  Copy,
  Trash2,
  RotateCcw,
  Link2,
  Image,
  Share2,
  Download,
  MoreVertical,
  Paperclip,
  Mic,
  Send,
  X,
} from ''

interface ReferenceCard {
  title: string
  url: string
}

const REFERENCES: ReferenceCard[] = [
  {
    title: 'Incorporating natural ecosystems..',
    url: 'https://pmc.ncbi.nlm.nih.gov/articles',
  },
  {
    title: 'Clarifying the effect of biodiversity..',
    url: 'https://pmc.ncbi.nlm.nih.gov/articles',
  },
  {
    title: 'Benefits Supplied to Human societies..',
    url: 'https://pmc.ncbi.nlm.nih.gov/articles',
  },
  {
    title: 'Benefits..',
    url: 'https://pmc.ncbi.nlm.nih.gov/articles',
  },
]

const SIDEBAR_ICONS = [
  { icon: Home, label: 'Home', active: false },
  { icon: BookOpen, label: 'Library', active: false },
  { icon: FileText, label: 'Documents', active: false },
  { icon: Bookmark, label: 'Bookmarks', active: false },
  { icon: Clock, label: 'History', active: false },
  { icon: FileText, label: 'Files', active: false },
]

export default function ChatPage() {
  const [input, setInput] = useState('')

  return (
    <div className="flex h-screen bg-white">
      {/* Sidebar */}
      <div className="w-16 bg-white flex flex-col items-center justify-between py-6 border-r border-gray-100">
        <div className="flex flex-col items-center gap-8">
          {/* Logo */}
          <div className="w-8 h-8 bg-red-600 rounded-full flex items-center justify-center">
            <div className="w-2 h-2 bg-white rounded-full" />
          </div>

          {/* Icons */}
          <div className="flex flex-col items-center gap-4">
            {SIDEBAR_ICONS.map((item, idx) => (
              <button
                key={idx}
                className={`p-2 rounded-lg transition-colors ${
                  idx === 0
                    ? 'text-red-600 bg-red-50'
                    : 'text-gray-500 hover:text-gray-700 hover:bg-gray-100'
                }`}
              >
                <item.icon size={20} />
              </button>
            ))}
          </div>
        </div>

        {/* Bottom icons */}
        <div className="flex flex-col items-center gap-4">
          <button className="p-2 rounded-lg text-gray-500 hover:text-gray-700 hover:bg-gray-100 transition-colors">
            <Settings size={20} />
          </button>
        </div>
      </div>

      {/* Main Chat Area */}
      <div className="flex-1 flex flex-col">
        {/* Header */}
        <div className="px-6 py-4 flex items-center justify-between bg-white border-b border-gray-100">
          <div className="flex items-center gap-2">
            <div className="flex items-center gap-2 px-3 py-1 bg-gray-50 rounded-lg">
              <div className="w-2 h-2 bg-red-600 rounded-full" />
              <span className="text-sm text-gray-700">Natural ecosystems</span>
              <X size={14} className="text-gray-500 cursor-pointer" />
            </div>
            <button className="p-1 text-gray-400 hover:text-gray-600">
              <span className="text-xl">+</span>
            </button>
          </div>

          <div className="flex items-center gap-2">
            <button className="p-2 rounded-lg text-gray-500 hover:text-gray-700 hover:bg-gray-100 transition-colors">
              <Image size={18} />
            </button>
            <button className="p-2 rounded-lg text-gray-500 hover:text-gray-700 hover:bg-gray-100 transition-colors">
              <FileText size={18} />
            </button>
            <button className="p-2 rounded-lg text-gray-500 hover:text-gray-700 hover:bg-gray-100 transition-colors">
              <Share2 size={18} />
            </button>
            <button className="p-2 rounded-lg text-gray-500 hover:text-gray-700 hover:bg-gray-100 transition-colors">
              <Download size={18} />
            </button>
            <button className="p-2 rounded-lg text-gray-500 hover:text-gray-700 hover:bg-gray-100 transition-colors">
              <MoreVertical size={18} />
            </button>
          </div>
        </div>

        {/* Messages with centered content */}
        <div className="flex-1 overflow-y-auto px-6 py-8">
          <div className="max-w-2xl mx-auto">
            {/* User Message */}
            <div className="mb-8">
              <div className="text-right mb-4">
                <p className="text-sm text-gray-700 inline-block bg-gray-100 px-4 py-2 rounded-lg">
                  Please try to find a paper about natural ecosystems
                </p>
              </div>
            </div>

            {/* Assistant Response */}
            <div>
              <div className="text-sm text-gray-900 space-y-4 mb-6">
                <p>Certainly! Here are some scholarly papers related to natural ecosystems that you might find useful:</p>

                <ol className="space-y-4 ml-4">
                  <li className="text-sm">
                    <strong>"Incorporating natural ecosystems into global health and food security"</strong>
                    <ul className="mt-2 space-y-1 ml-4 list-disc text-gray-700">
                      <li>Published by: PMC (PubMed Central)</li>
                      <li>
                        Summary: This paper discusses how natural ecosystems such as forests, coral reefs, mangroves, and grasslands provide essential goods and services that support human health and food security.{' '}
                        <a href="#" className="text-blue-600 hover:underline">
                          pmc.ncbi.nlm.nih.gov
                        </a>
                      </li>
                    </ul>
                  </li>

                  <li className="text-sm mt-4">
                    <strong>"Clarifying the effect of biodiversity on productivity in natural ecosystems"</strong>
                    <ul className="mt-2 space-y-1 ml-4 list-disc text-gray-700">
                      <li>Published by: Nature Communications</li>
                      <li>
                        Summary: The study estimates that increases in plot-level species richness caused productivity to decline, challenging prior studies that suggested a positive relationship. It utilizes longitudinal data from 43 grasslands in 11 countries to draw causal inferences from observational data.{' '}
                        <a href="#" className="text-blue-600 hover:underline">
                          theaustralian.com.au+15nature.com
                        </a>
                      </li>
                    </ul>
                  </li>
                </ol>

                <p>If you need assistance accessing any of these papers or require further information, feel free to ask!</p>
              </div>

              {/* Reference Cards */}
              <div className="flex gap-3 overflow-x-auto py-4 mb-6 scrollbar-hide">
                {REFERENCES.map((ref, idx) => (
                  <div
                    key={idx}
                    className="min-w-[180px] p-3 border border-gray-200 rounded-lg bg-white hover:shadow-sm transition-shadow cursor-pointer group"
                  >
                    <h4 className="text-sm font-medium text-gray-900 mb-2 line-clamp-2">{ref.title}</h4>
                    <div className="flex items-center gap-1 text-xs text-gray-500 group-hover:text-gray-700">
                      <Link2 size={12} />
                      <span className="truncate">{ref.url}</span>
                    </div>
                  </div>
                ))}
              </div>

              {/* Action Buttons */}
              <div className="flex gap-2">
                <button className="p-2 rounded-lg hover:bg-gray-100 transition-colors">
                  <RotateCcw size={16} className="text-gray-500" />
                </button>
                <button className="p-2 rounded-lg hover:bg-gray-100 transition-colors">
                  <Copy size={16} className="text-gray-500" />
                </button>
                <button className="p-2 rounded-lg hover:bg-gray-100 transition-colors">
                  <Trash2 size={16} className="text-gray-500" />
                </button>
              </div>
            </div>
          </div>
        </div>

        {/* Input Area */}
        <div className="px-6 py-4 bg-white border-t border-gray-100">
          <div className="max-w-2xl mx-auto">
            <div className="flex gap-3 mb-3">
              <button className="p-2 text-gray-400 hover:text-gray-600 transition-colors">
                <Paperclip size={18} />
              </button>
              <button className="p-2 text-gray-400 hover:text-gray-600 transition-colors">
                <Mic size={18} />
              </button>
            </div>

            <div className="flex gap-2 items-end">
              <input
                type="text"
                value={input}
                onChange={(e) => setInput(e.target.value)}
                placeholder="Curious? Ask and dive into scholarly insights"
                className="flex-1 px-4 py-3 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-600 bg-white text-sm"
              />
              <button className="p-3 rounded-full bg-gray-900 text-white hover:bg-gray-800 transition-colors">
                <Send size={18} />
              </button>
            </div>

            <div className="mt-3 flex items-center gap-2 text-xs text-gray-500">
              <div className="w-6 h-6 rounded-full bg-red-100 flex items-center justify-center">
                <div className="w-1.5 h-1.5 bg-red-600 rounded-full" />
              </div>
              <span>arc-1a</span>
            </div>

            <p className="text-xs text-gray-400 mt-3">Resarc can make mistakes. Double check the important information</p>
          </div>
        </div>
      </div>
    </div>
  )
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
